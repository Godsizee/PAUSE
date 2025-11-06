// public/assets/js/planer-interactions-1.js
// MODIFIZIERT: Logik in openModal, debouncedConflictCheck und initializeEntryModal (handleSubmit)
// korrigiert, um mit den neuen Datenstrukturen (Maps von Arrays) und parallelen Einträgen
// korrekt umzugehen.
// KORRIGIERT: Syntaktische Fehler (eingestreute Zeichen wie 'm', 's', 'Maus') entfernt.

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
 * KORRIGIERT: Liest Daten aus dem Formular und dem State, nicht mehr nur aus state.selection.
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

            // *** KORRIGIERT: Daten direkt aus dem Formular und state.selection lesen ***
            
            const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
            const entryId = DOM.form.querySelector('#entry_id').value || null;
            const blockId = DOM.form.querySelector('#block_id').value || null;

            let startPeriod, endPeriod;
            
            if (blockId && !entryId) {
                // Bearbeite einen Block (basierend auf der Auswahl)
                if (state.selection && state.selection.cells.length > 0) {
                    startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
                    endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
                } else {
                    // Fallback (sollte nicht passieren, wenn openModal korrekt funktioniert)
                    startPeriod = parseInt(DOM.form.querySelector('#modal_period_number').value);
                    endPeriod = startPeriod;
                }
            } else {
                // Einzelner Eintrag (neu oder Bearbeitung)
                // (Die Perioden-Auswahl wird für einzelne parallele Einträge nicht unterstützt)
                startPeriod = parseInt(DOM.form.querySelector('#modal_period_number').value);
                endPeriod = startPeriod;
            }

            if (!startPeriod || !endPeriod) {
                // console.warn("Konfliktprüfung übersprungen: Periode nicht ermittelbar.");
                hideConflicts();
                return;
            }
            
            const data = {
                year: DOM.yearSelector.value,
                calendar_week: DOM.weekSelector.value,
                day_of_week: dayOfWeek,
                start_period_number: startPeriod,
                end_period_number: endPeriod,
                teacher_id: DOM.form.querySelector('#teacher_id').value,
                room_id: DOM.form.querySelector('#room_id').value,
                class_id: state.editingClassId, // Holt die ID der Klasse, die bearbeitet wird
                entry_id: entryId,
                block_id: blockId,
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

/** * Behandelt Klicks auf Zellen im Grid (Single Click für Selektion)
 * @param {HTMLElement} cell - Die angeklickte Zelle (TD)
 * @param {HTMLElement} container - Der Grid-Container (timetableContainer oder templateEditorGridContainer)
 */
export function handleCellClick(cell, container) {
    if (!container) { // Fallback, falls Container nicht übergeben wird
        container = DOM.timetableContainer;
    }
    const clickedDay = cell.dataset.day;
    const clickedPeriod = parseInt(cell.dataset.period);
    const state = getState();

    // *** KORREKTUR: Die Klick-Logik MUSS das .planner-entry (den Div) berücksichtigen ***
    // (Annahme: Der Event-Listener in interactions-2.js wurde noch nicht angepasst und übergibt 'cell' (das TD))
    
    // HINWEIS: Die bestehende `handleCellClick`-Logik ist für Multi-Zellen-Auswahl (Block-Erstellung)
    // durch Ziehen oder Klicken. Sie ist NICHT für die Auswahl *einzelner* paralleler Einträge gedacht.
    
    // Wir behalten die Block-Auswahl-Logik (Klick auf <td>), da sie für das Erstellen neuer Blöcke 
    // und das Bearbeiten von Vorlagen-Blöcken (Template-Editor) benötigt wird.
    
    // Die Auswahl eines *spezifischen* parallelen Eintrags erfolgt über DBLCLICK,
    // was direkt `openModal` auslöst (siehe Anpassung in Teil 2).

    // --- Bisherige Block-Auswahl-Logik (leicht angepasst) ---
    
    // Finde Daten des *ersten* Eintrags in der Zelle (falls vorhanden), um die Gruppe zu bestimmen
    const firstEntryInCell = cell.querySelector('.planner-entry');
    const cellData = firstEntryInCell ? firstEntryInCell.dataset : {}; // Verwende .dataset des Eintrags, nicht der Zelle

    const startCellData = state.selection.start ? state.selection.start.cellData : {};
    
    const isSameGroupAsStart = (startCellData.blockId && cellData.blockId === startCellData.blockId) ||
                             (startCellData.entryId && cellData.entryId === startCellData.entryId) ||
                             (startCellData.substitutionId && cellData.substitutionId === startCellData.substitutionId);

    const startIsEmpty = !startCellData.entryId && !startCellData.blockId && !startCellData.substitutionId;
    const currentIsEmpty = !cellData.entryId && !cellData.blockId && !cellData.substitutionId;

    if (
        !state.selection.start ||
        clickedDay !== state.selection.start.day ||
        cell.classList.contains('default-entry') || // Prevent selecting FU cells
        (!isSameGroupAsStart && !(startIsEmpty && currentIsEmpty))
    ) {
        clearSelectionState(state.selection.cells); // Clear previous visual selection
        setSelectionState({ start: { day: clickedDay, period: clickedPeriod, cell: cell, cellData: cellData }, end: null, cells: [cell] });
        cell.classList.add('selected');
        return;
    }

    // --- Extend selection ---
    state.selection.end = { day: clickedDay, period: clickedPeriod };
    const startPeriod = Math.min(state.selection.start.period, state.selection.end.period);
    const endPeriod = Math.max(state.selection.start.period, state.selection.end.period);

    state.selection.cells.forEach(c => c.classList.remove('selected'));
    const newSelectionCells = [];

    for (let p = startPeriod; p <= endPeriod; p++) {
        const cellToSelect = container.querySelector(`.grid-cell[data-day='${clickedDay}'][data-period='${p}']`);
        if (cellToSelect) {
            const firstEntryInCellToSelect = cellToSelect.querySelector('.planner-entry');
            const currentCellData = firstEntryInCellToSelect ? firstEntryInCellToSelect.dataset : {};

            const isCurrentSameGroup = (startCellData.blockId && currentCellData.blockId === startCellData.blockId) ||
                                     (startCellData.entryId && currentCellData.entryId === startCellData.entryId) ||
                                     (startCellData.substitutionId && currentCellData.substitutionId === startCellData.substitutionId);
            const isCurrentEmpty = !currentCellData.entryId && !currentCellData.blockId && !currentCellData.substitutionId;

            if ((startIsEmpty && !isCurrentEmpty) || (!startIsEmpty && !isCurrentSameGroup)) {
                clearSelectionState(state.selection.cells.concat(newSelectionCells));
                setSelectionState({ start: { day: clickedDay, period: clickedPeriod, cell: cell, cellData: cellData }, end: null, cells: [cell] });
                cell.classList.add('selected');
                return; 
            }

            cellToSelect.classList.add('selected');
            newSelectionCells.push(cellToSelect);
        }
    }
    state.selection.cells = newSelectionCells;
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

            // Find original subject ID from the (potentially hidden) regular entry ID
            const entryId = DOM.form.querySelector('#entry_id').value; // ID des regulären Eintrags
            if (entryId) {
                 // KORREKTUR: Finde den regulären Eintrag im flachen Array
                 const regularEntry = state.currentTimetable.find(e => e.entry_id == entryId);
                 data.original_subject_id = regularEntry?.subject_id || null;
            } else {
                 data.original_subject_id = null; // Kein zugrundeliegender Eintrag (z.B. Sonderevent in Lücke)
            }
            
            data.class_id = state.editingClassId; // Ensure class_id is set for substitution
            if (!data.class_id) {
                showToast("Fehler: Klasse für Vertretung konnte nicht ermittelt werden.", 'error');
                return; // Stop if class ID is missing
            }
            promise = saveSubstitution(data);
        } else { // Regulärer Modus
            // *** KORREKTUR: Verwende die IDs aus dem Formular UND state.selection für Spannen ***
            const entryId = DOM.form.querySelector('#entry_id').value || null;
            const blockId = DOM.form.querySelector('#block_id').value || null;

            let startPeriod, endPeriod;
            
            // KORRIGIERTE LOGIK:
            // Wenn wir eine Auswahl haben (state.selection), hat der Benutzer geklickt/gezogen.
            // Nutze diese Auswahl für die Perioden.
            if (state.selection && state.selection.cells && state.selection.cells.length > 0) {
                startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
                endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
            } else {
                // Fallback (z.B. wenn Modal ohne Klick geöffnet wurde? sollte nicht passieren)
                startPeriod = parseInt(DOM.form.querySelector('#modal_period_number').value);
                endPeriod = startPeriod;
            }

            data.start_period_number = startPeriod;
            data.end_period_number = endPeriod;
            data.year = DOM.yearSelector.value;
            data.calendar_week = DOM.weekSelector.value;
            
            // KORREKTUR: class_id muss explizit gesetzt werden
            if (DOM.form.querySelector('#modal_editing_template').value === 'true') {
                 data.class_id = DOM.modal.querySelector('#template_class_id').value || '0';
            } else {
                 data.class_id = state.editingClassId;
            }

            if (!data.class_id && data.class_id !== '0') {
                showToast("Fehler: Konnte Klasse nicht ermitteln.", 'error');
                return;
            }

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
        let confirmMsg;
        const state = getState();
        const promises = []; // Array für mehrere Lösch-Promises
        
        // *** KORREKTUR: Daten direkt aus dem Formular statt aus state.selection holen ***
        const substitutionId = DOM.form.querySelector('#substitution_id').value;
        const entryId = DOM.form.querySelector('#entry_id').value;
        const blockId = DOM.form.querySelector('#block_id').value;

        if (state.activeMode === 'substitution') {
            if (!substitutionId) {
                showToast("Fehler: Keine Vertretungs-ID gefunden.", 'error');
                return;
            }
            
            confirmMsg = 'Soll diese Vertretung gelöscht werden? (Die reguläre Stunde bleibt erhalten)';
            
            // Nur Vertretung löschen
            promises.push(deleteSubstitution(substitutionId));

            // Optional: Wenn der Benutzer auch die reguläre Stunde löschen will (komplexer)
            // if (await showConfirm("Zusatzfrage", "Soll die zugrundeliegende reguläre Stunde auch gelöscht werden?")) { ... }
            
            if (await showConfirm("Löschen bestätigen", confirmMsg)) {
                try {
                    const responses = await Promise.all(promises);
                    if (responses.every(r => r && r.success)) {
                        showToast("Vertretung erfolgreich gelöscht.", 'success');
                        closeModal();
                        loadPlanData();
                    } else {
                        throw new Error("Eintrag konnte nicht gelöscht werden.");
                    }
                } catch (error) { /* Fehler wird von apiFetch behandelt */ }
            }

        } else { // Regular mode
            let body;
            if (blockId) {
                body = { block_id: blockId };
                confirmMsg = 'Soll dieser gesamte Block wirklich gelöscht werden?';
            } else if (entryId) {
                body = { entry_id: entryId };
                confirmMsg = 'Soll diese reguläre Stunde wirklich gelöscht werden?';
            } else {
                showToast("Kein Eintrag zum Löschen ausgewählt.", 'error'); return;
            }
            
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


/** * Öffnet das Eintragsmodal und füllt es basierend auf dem Klick-Ereignis.
* KORRIGIERT: Akzeptiert das Event 'e' anstelle von 'isTemplateEdit'.
 * KORRIGIERT: Findet den korrekten Eintrag in den State-Arrays.
 * KORRIGIERT: Setzt editingClassId korrekt.
 */
export function openModal(event, isTemplateEdit = false) {
    const state = getState();
    DOM.form.reset(); // Clear previous data
    hideConflicts(); // Clear old conflicts

    // *** KORREKTUR: Ermittle das Klick-Ziel ***
    const target = event.target;
    const clickedEntryElement = target.closest('.planner-entry');
    const clickedCellElement = target.closest('.grid-cell');

    if (!clickedCellElement) return; // Klick außerhalb des Grids

    const day = clickedCellElement.dataset.day;
    const period = clickedCellElement.dataset.period;
    const cellKey = clickedCellElement.dataset.cellKey; // "day-period"
    let entryId = null;
    let substitutionId = null;
    let blockId = null;
    let entryToEdit = null;
    let regularEntryForSub = null;
    let modeToSwitchTo = 'regular';
    let editingClassId = null;

    // Setze versteckte Felder (Tag/Stunde)
    DOM.form.querySelector('#modal_day_of_week').value = day;
    DOM.form.querySelector('#modal_period_number').value = period;
    DOM.form.querySelector('#modal_editing_template').value = isTemplateEdit ? 'true' : 'false';

    // *** KORREKTUR: Logik basierend auf Klick-Ziel (Eintrag vs. Zelle) ***
    
    if (isTemplateEdit) {
        // --- Template-Editor-Logik ---
        DOM.modal.querySelector('#template-class-select-container').style.display = 'block';
        DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'none';
        
        const templateData = state.currentTemplateEditorData || [];
        if (clickedEntryElement) {
            entryId = clickedEntryElement.dataset.templateEntryId || null;
            blockId = clickedEntryElement.dataset.blockId || null; // (ist block_ref)
            if (blockId) entryToEdit = templateData.find(e => e.block_ref === blockId);
            else if (entryId) entryToEdit = templateData.find(e => e.template_entry_id == entryId);
        }
        editingClassId = entryToEdit ? entryToEdit.class_id : '0'; // Default '0'
        modeToSwitchTo = 'regular';

    } else {
        // --- Normale Planer-Logik ---
        DOM.modal.querySelector('#template-class-select-container').style.display = 'none';
        DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'block';

        if (clickedEntryElement) {
            // A. Klick auf einen vorhandenen Eintrag
            entryId = clickedEntryElement.dataset.entryId || null;
            substitutionId = clickedEntryElement.dataset.substitutionId || null;
            blockId = clickedEntryElement.dataset.blockId || null;

            if (substitutionId) {
                // A1. Vertretung angeklickt
                // KORREKTUR: Finde in der Map (Array)
                entryToEdit = (state.substitutions[cellKey] || []).find(s => s.substitution_id == substitutionId);
                if (entryToEdit) {
                    // Finde den regulären Eintrag für diese Zelle (falls vorhanden)
                    const regularEntriesInCell = state.timetable[cellKey] || [];
                    // KORREKTUR: Finde basierend auf class_id UND original_subject_id
                    regularEntryForSub = regularEntriesInCell.find(e => e.class_id == entryToEdit.class_id && e.subject_id == entryToEdit.original_subject_id);
                    editingClassId = entryToEdit.class_id;
                }
                modeToSwitchTo = 'substitution';
            } else if (entryId || blockId) {
                // A2. Regulären Eintrag angeklickt
                // KORREKTUR: Finde in der Map (Array)
                const entriesInCell = state.timetable[cellKey] || [];
                if (blockId) {
                    entryToEdit = entriesInCell.find(e => e.block_id === blockId);
                } else {
                    entryToEdit = entriesInCell.find(e => e.entry_id == entryId);
                }
                if(entryToEdit) editingClassId = entryToEdit.class_id;
                modeToSwitchTo = 'regular';
            }
        } else {
            // B. Klick auf leere Zelle (neuer Eintrag)
            modeToSwitchTo = 'regular';
            // KORREKTUR: Setze editingClassId korrekt für Klassen- vs. Lehreransicht
            editingClassId = (state.currentViewMode === 'class') ? state.selectedClassId : '0';
        }
    }
    
    updateState({ editingClassId: editingClassId }); // Speichere die Klasse des Eintrags

    // --- Versteckte Felder setzen ---
    if (regularEntryForSub) {
        // Bearbeite Vertretung, speichere IDs des regulären Eintrags
        DOM.form.querySelector('#entry_id').value = regularEntryForSub.entry_id || '';
        DOM.form.querySelector('#block_id').value = regularEntryForSub.block_id || '';
        DOM.form.querySelector('#substitution_id').value = entryToEdit?.substitution_id || '';
    } else if (entryToEdit && modeToSwitchTo === 'regular') {
        // Bearbeite regulären Eintrag
        DOM.form.querySelector('#entry_id').value = isTemplateEdit ? (entryToEdit.template_entry_id || '') : (entryToEdit.entry_id || '');
        DOM.form.querySelector('#block_id').value = isTemplateEdit ? (entryToEdit.block_ref || '') : (entryToEdit.block_id || '');
        DOM.form.querySelector('#substitution_id').value = '';
    } else {
        // Neuer Eintrag
        DOM.form.querySelector('#entry_id').value = '';
        DOM.form.querySelector('#block_id').value = '';
        DOM.form.querySelector('#substitution_id').value = '';
    }

    // --- Modal-Titel setzen ---
    // KORREKTUR: Verwende state.selection (das im Klick-Event in interactions-2.js gesetzt wurde)
    if (state.selection && (state.selection.cells.length > 1 || (state.selection.start && state.selection.start.cellData.blockId))) {
        const startP = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
        const endP = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
        DOM.modalTitle.textContent = `Block bearbeiten (${DOM.days[day-1]}, ${startP}. - ${endP}. Stunde)`;
    } else {
        DOM.modalTitle.textContent = `Eintrag bearbeiten (${DOM.days[day-1]}, ${period}. Stunde)`;
    }


    // --- Formular füllen ---
    switchMode(modeToSwitchTo, isTemplateEdit); // Tabs umschalten

    if (modeToSwitchTo === 'regular') {
        if(entryToEdit){ // Bearbeite regulären Eintrag
            DOM.form.querySelector('#subject_id').value = entryToEdit.subject_id;
            DOM.form.querySelector('#teacher_id').value = entryToEdit.teacher_id;
            DOM.form.querySelector('#room_id').value = entryToEdit.room_id;
            DOM.regularCommentInput.value = entryToEdit.comment || '';
            if (isTemplateEdit) DOM.modal.querySelector('#template_class_id').value = entryToEdit.class_id || '0';
        } else { // Neuer regulärer Eintrag
            DOM.regularCommentInput.value = '';
            // (Standardwerte werden nicht mehr vorausgefüllt, um parallele Einträge zu erleichtern)
            if (isTemplateEdit) DOM.modal.querySelector('#template_class_id').value = editingClassId || '0';
        }
    } else if (modeToSwitchTo === 'substitution') {
        // Fülle reguläre Felder (deaktiviert) mit Originaldaten
        DOM.form.querySelector('#subject_id').value = regularEntryForSub?.subject_id || '';
        DOM.form.querySelector('#teacher_id').value = regularEntryForSub?.teacher_id || '';
        DOM.form.querySelector('#room_id').value = regularEntryForSub?.room_id || '';
        DOM.form.querySelector('#original_subject_id').value = regularEntryForSub?.subject_id || ''; // Hidden field
        DOM.regularCommentInput.value = regularEntryForSub?.comment || ''; 

        if(entryToEdit){ // Bearbeite bestehende Vertretung
            DOM.form.querySelector('#substitution_type').value = entryToEdit.substitution_type;
            DOM.form.querySelector('#new_teacher_id').value = entryToEdit.new_teacher_id || '';
            DOM.form.querySelector('#new_subject_id').value = entryToEdit.new_subject_id || '';
            DOM.form.querySelector('#new_room_id').value = entryToEdit.new_room_id || '';
            DOM.substitutionCommentInput.value = entryToEdit.comment || '';
        } else { // Neue Vertretung erstellen
            DOM.form.querySelector('#substitution_type').value = 'Vertretung';
            DOM.substitutionCommentInput.value = '';
            DOM.form.querySelector('#new_subject_id').value = regularEntryForSub?.subject_id || '';
            DOM.form.querySelector('#new_room_id').value = regularEntryForSub?.room_id || '';
            DOM.form.querySelector('#new_teacher_id').value = '';
        }
        updateSubstitutionFields(); // Zeige korrekte Felder
    }

    updateDeleteButtonVisibility();
    DOM.modal.classList.add('visible');
    if (!isTemplateEdit) debouncedConflictCheck(); // Initiale Konfliktprüfung
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
        const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
        if (!dayOfWeek) { 
            showToast("Kann keine Vertretung ohne ausgewählten Tag erstellen.", 'error');
            setTimeout(() => switchMode('regular', isTemplateEditor), 0);
            return;
        }
        updateState({ selectedDate: getDateForDayInWeek(dayOfWeek, DOM.yearSelector.value, DOM.weekSelector.value) });

        // (Vor-Befüllung der Felder wurde bereits in openModal() erledigt)
        
        updateSubstitutionFields(); // Show/hide fields based on the selected substitution type

    }
    updateDeleteButtonVisibility(); // Update delete button visibility based on mode/IDs
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

    const canDelete = !isTemplateEdit && (
        (getState().activeMode === 'substitution' && substitutionId) ||
        (getState().activeMode === 'regular' && (entryId || blockId))
    );
    
    // KORREKTUR: Tippfehler 'SO' zu 'DOM' (bereits im Original-Code korrekt)
    DOM.deleteBtn.style.display = canDelete ? 'block' : 'none';
}