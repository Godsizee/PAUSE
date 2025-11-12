import * as DOM from './planer-dom.js';
import { getState, updateState, clearSelectionState } from './planer-state.js';
import {
    checkConflicts, saveEntry, deleteEntry, saveSubstitution, deleteSubstitution,
    loadPlanData
} from './planer-api.js';
import { showConflicts, hideConflicts } from './planer-ui.js';
import { renderTimetableGrid } from './planer-timetable.js';
import { showToast, showConfirm } from './notifications.js';
import { getDateForDayInWeek, escapeHtml } from './planer-utils.js';
export function initializeEntryModalInteractions() {
    initializeEntryModal();
}
function initializeEntryModal() {
    DOM.conflictCheckFields.forEach(field => {
        field.addEventListener('change', debouncedConflictCheck);
    });
    DOM.modalTabs.forEach(tab => tab.addEventListener('click', () => {
        const isTemplateEdit = DOM.form.querySelector('#modal_editing_template').value === 'true';
        switchMode(tab.dataset.mode, isTemplateEdit);
    }));
    DOM.substitutionTypeSelect.addEventListener('change', () => {
        updateSubstitutionFields();
        hideConflicts(); // Konflikte zurücksetzen, da sich die Logik ändert
    });
    DOM.form.addEventListener('submit', handleSaveEntry);
    DOM.deleteBtn.addEventListener('click', handleDeleteEntry);
    DOM.modal.addEventListener('click', (e) => {
        if (e.target.id === 'timetable-modal' || e.target.id === 'modal-cancel-btn') {
            closeModal();
        }
    });
}
export function openModal(event, isTemplateEditor = false) {
    const state = getState();
    DOM.form.reset();
    hideConflicts();
    const target = event.target;
    const clickedCellElement = target.closest('.grid-cell');
    if (!clickedCellElement) return;
    const clickedEntryElement = clickedCellElement.querySelector('.planner-entry');
    const day = clickedCellElement.dataset.day;
    const period = clickedCellElement.dataset.period;
    const cellKey = `${day}-${period}`;
    let entryId = null;
    let substitutionId = null;
    let blockId = null;
    let entryToEdit = null;
    let regularEntryForSub = null;
    let modeToSwitchTo = 'regular';
    let editingClassId = null;
    DOM.form.querySelector('#modal_day_of_week').value = day;
    DOM.form.querySelector('#modal_period_number').value = period;
    DOM.form.querySelector('#modal_editing_template').value = isTemplateEditor ? 'true' : 'false';
    if (isTemplateEditor) {
        DOM.modal.querySelector('#template-class-select-container').style.display = 'block';
        DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'none';
        const templateData = state.currentTemplateEditorData || [];
        if (clickedEntryElement) { // KORREKTUR: Verwendet die neue Variable
            entryId = clickedEntryElement.dataset.templateEntryId || null;
            blockId = clickedEntryElement.dataset.blockId || null;
            if (blockId) entryToEdit = templateData.find(e => e.block_ref === blockId);
            else if (entryId) entryToEdit = templateData.find(e => e.template_entry_id == entryId);
        }
        editingClassId = entryToEdit ? entryToEdit.class_id : '0'; // Standard '0' (Keine Klasse) für neue Einträge
        modeToSwitchTo = 'regular';
    } else {
        DOM.modal.querySelector('#template-class-select-container').style.display = 'none';
        DOM.modal.querySelector('.modal-tabs .tab-button[data-mode="substitution"]').style.display = 'block';
        if (clickedEntryElement) { // KORREKTUR: Verwendet die neue Variable
            entryId = clickedEntryElement.dataset.entryId || null;
            substitutionId = clickedEntryElement.dataset.substitutionId || null;
            blockId = clickedEntryElement.dataset.blockId || null;
            if (substitutionId) {
                entryToEdit = (state.substitutions[cellKey] || []).find(s => s.substitution_id == substitutionId);
                if (entryToEdit) {
                    const regularEntriesInCell = state.timetable[cellKey] || [];
                    regularEntryForSub = regularEntriesInCell.find(e => e.class_id == entryToEdit.class_id && e.subject_id == entryToEdit.original_subject_id);
                    editingClassId = entryToEdit.class_id;
                }
                modeToSwitchTo = 'substitution';
            } else if (entryId || blockId) {
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
            modeToSwitchTo = 'regular';
            editingClassId = (state.currentViewMode === 'class') ? state.selectedClassId : null;
        }
    }
    updateState({ editingClassId: editingClassId });
    if (modeToSwitchTo === 'substitution') {
        DOM.form.querySelector('#entry_id').value = regularEntryForSub?.entry_id || '';
        DOM.form.querySelector('#block_id').value = regularEntryForSub?.block_id || '';
        DOM.form.querySelector('#substitution_id').value = entryToEdit?.substitution_id || '';
    } else if (modeToSwitchTo === 'regular' && entryToEdit) {
        DOM.form.querySelector('#entry_id').value = isTemplateEditor ? (entryToEdit.template_entry_id || '') : (entryToEdit.entry_id || '');
        DOM.form.querySelector('#block_id').value = isTemplateEditor ? (entryToEdit.block_ref || '') : (entryToEdit.block_id || '');
        DOM.form.querySelector('#substitution_id').value = '';
    } else {
        DOM.form.querySelector('#entry_id').value = '';
        DOM.form.querySelector('#block_id').value = '';
        DOM.form.querySelector('#substitution_id').value = '';
    }
    if (state.selection && (state.selection.cells.length > 1 || (state.selection.start && state.selection.start.cellData.blockId))) {
        const startP = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
        const endP = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
        DOM.modalTitle.textContent = `Block bearbeiten (${DOM.days[day-1]}, ${startP}. - ${endP}. Stunde)`;
    } else {
        DOM.modalTitle.textContent = `Eintrag bearbeiten (${DOM.days[day-1]}, ${period}. Stunde)`;
    }
    switchMode(modeToSwitchTo, isTemplateEditor);
    if (modeToSwitchTo === 'regular') {
        if(entryToEdit){
            DOM.form.querySelector('#subject_id').value = entryToEdit.subject_id;
            DOM.form.querySelector('#teacher_id').value = entryToEdit.teacher_id;
            DOM.form.querySelector('#room_id').value = entryToEdit.room_id;
            DOM.regularCommentInput.value = entryToEdit.comment || '';
            if (isTemplateEditor) DOM.modal.querySelector('#template_class_id').value = entryToEdit.class_id || '0';
        } else {
            DOM.regularCommentInput.value = '';
            if (isTemplateEditor) DOM.modal.querySelector('#template_class_id').value = editingClassId || '0';
        }
    } else if (modeToSwitchTo === 'substitution') {
        DOM.form.querySelector('#subject_id').value = regularEntryForSub?.subject_id || '';
        DOM.form.querySelector('#teacher_id').value = regularEntryForSub?.teacher_id || '';
        DOM.form.querySelector('#room_id').value = regularEntryForSub?.room_id || '';
        DOM.form.querySelector('#original_subject_id').value = regularEntryForSub?.subject_id || '';
        DOM.regularCommentInput.value = regularEntryForSub?.comment || '';
        if(entryToEdit){
            DOM.form.querySelector('#substitution_type').value = entryToEdit.substitution_type;
            DOM.form.querySelector('#new_teacher_id').value = entryToEdit.new_teacher_id || '';
            DOM.form.querySelector('#new_subject_id').value = entryToEdit.new_subject_id || '';
            DOM.form.querySelector('#new_room_id').value = entryToEdit.new_room_id || '';
            DOM.substitutionCommentInput.value = entryToEdit.comment || '';
        } else {
            DOM.form.querySelector('#substitution_type').value = 'Vertretung';
            DOM.substitutionCommentInput.value = '';
            DOM.form.querySelector('#new_subject_id').value = regularEntryForSub?.subject_id || '';
            DOM.form.querySelector('#new_room_id').value = regularEntryForSub?.room_id || '';
            DOM.form.querySelector('#new_teacher_id').value = '';
        }
        updateSubstitutionFields();
    }
    updateDeleteButtonVisibility();
    DOM.modal.classList.add('visible');
    if (!isTemplateEditor) debouncedConflictCheck();
}
export function closeModal() {
    clearSelectionState(getState().selection.cells);
    DOM.modal.classList.remove('visible');
    hideConflicts();
    updateState({ editingClassId: null });
    if (DOM.form) {
        DOM.form.querySelector('#modal_editing_template').value = 'false';
        const templateClassSelect = DOM.form.querySelector('#template-class-select-container');
        if (templateClassSelect) {
            templateClassSelect.style.display = 'none';
        }
    }
}
export function switchMode(mode, isTemplateEditor = false) {
    updateState({ activeMode: mode });
    DOM.modalTabs.forEach(tab => tab.classList.toggle('active', tab.dataset.mode === mode));
    DOM.regularFields.classList.toggle('active', mode === 'regular');
    DOM.substitutionFields.classList.toggle('active', mode === 'substitution');
    hideConflicts();
    if (mode === 'substitution') {
        const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
        if (!dayOfWeek) {
            showToast("Kann keine Vertretung ohne ausgewählten Tag erstellen.", 'error');
            setTimeout(() => switchMode('regular', isTemplateEditor), 0);
            return;
        }
        updateState({ selectedDate: getDateForDayInWeek(dayOfWeek, DOM.yearSelector.value, DOM.weekSelector.value) });
        updateSubstitutionFields();
    }
    updateDeleteButtonVisibility();
    if (!isTemplateEditor) debouncedConflictCheck();
}
export function updateSubstitutionFields() {
    const type = DOM.substitutionTypeSelect.value;
    DOM.modal.querySelectorAll('#substitution-details .sub-field').forEach(field => {
        const types = field.dataset.types ? JSON.parse(field.dataset.types) : [];
        field.style.display = types.includes(type) ? 'block' : 'none';
    });
}
export function updateDeleteButtonVisibility() {
    const entryId = DOM.form.querySelector('#entry_id').value;
    const substitutionId = DOM.form.querySelector('#substitution_id').value;
    const blockId = DOM.form.querySelector('#block_id').value;
    const isTemplateEdit = DOM.form.querySelector('#modal_editing_template').value === 'true';
    const canDelete = !isTemplateEdit && (
        (getState().activeMode === 'substitution' && substitutionId) ||
        (getState().activeMode === 'regular' && (entryId || blockId))
    );
    DOM.deleteBtn.style.display = canDelete ? 'block' : 'none';
}
const debouncedConflictCheck = () => {
    clearTimeout(getState().conflictCheckTimeout);
    updateState({
        conflictCheckTimeout: setTimeout(async () => {
            const state = getState();
            if (state.activeMode !== 'regular' || (DOM.form && DOM.form.querySelector('#modal_editing_template').value === 'true')) {
                hideConflicts();
                return;
            }
            const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
            const entryId = DOM.form.querySelector('#entry_id').value || null;
            const blockId = DOM.form.querySelector('#block_id').value || null;
            let startPeriod, endPeriod;
            if (state.selection && state.selection.cells.length > 0) {
                startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
                endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
            } else {
                startPeriod = parseInt(DOM.form.querySelector('#modal_period_number').value);
                endPeriod = startPeriod;
            }
            if (!startPeriod || !endPeriod) {
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
                class_id: state.editingClassId,
                entry_id: entryId,
                block_id: blockId,
            };
            if (!data.teacher_id || !data.room_id || !data.class_id || !data.day_of_week || !data.start_period_number) {
                hideConflicts();
                return;
            }
            try {
                await checkConflicts(data);
                hideConflicts(); // API war erfolgreich (keine Konflikte)
            } catch (error) {
                console.error("Konfliktprüfung ergab Fehler:", error);
                if (error.message) {
                    showConflicts(error.message.split("\n"));
                } else {
                    showConflicts(["Unbekannter Fehler bei der Konfliktprüfung."]);
                }
            }
        }, 300) // 300ms Verzögerung
    });
};
async function handleSaveEntry(e) {
    e.preventDefault();
    if (getState().conflictCheckTimeout) {
        await getState().lastConflictCheckPromise;
    }
    if (DOM.conflictWarningBox && DOM.conflictWarningBox.style.display !== 'none') {
        showToast("Speichern nicht möglich: Es bestehen Konflikte.", 'error');
        return;
    }
    const formData = new FormData(DOM.form);
    const state = getState();
    if (state.activeMode === 'regular') {
        formData.set('comment', DOM.regularCommentInput.value);
        formData.delete('substitution_type');
        formData.delete('new_teacher_id');
        formData.delete('new_subject_id');
        formData.delete('new_room_id');
        formData.delete('original_subject_id');
    } else {
        formData.set('comment', DOM.substitutionCommentInput.value);
    }
    const data = Object.fromEntries(formData.entries());
    let promise;
    if (state.activeMode === 'substitution') {
        const dayOfWeek = DOM.form.querySelector('#modal_day_of_week').value;
        data.date = getDateForDayInWeek(dayOfWeek, DOM.yearSelector.value, DOM.weekSelector.value);
        const entryId = DOM.form.querySelector('#entry_id').value;
        if (entryId) {
            const regularEntry = state.currentTimetable.find(e => e.entry_id == entryId);
            data.original_subject_id = regularEntry?.subject_id || null;
        } else {
            data.original_subject_id = null;
        }
        data.class_id = state.editingClassId;
        if (!data.class_id) {
            showToast("Fehler: Klasse für Vertretung konnte nicht ermittelt werden.", 'error');
            return;
        }
        promise = saveSubstitution(data);
    } else {
        const entryId = DOM.form.querySelector('#entry_id').value || null;
        const blockId = DOM.form.querySelector('#block_id').value || null;
        let startPeriod, endPeriod;
        if (state.selection && state.selection.cells.length > 0) {
            startPeriod = Math.min(...state.selection.cells.map(c => parseInt(c.dataset.period)));
            endPeriod = Math.max(...state.selection.cells.map(c => parseInt(c.dataset.period)));
        } else {
            startPeriod = parseInt(DOM.form.querySelector('#modal_period_number').value);
            endPeriod = startPeriod;
        }
        data.start_period_number = startPeriod;
        data.end_period_number = endPeriod;
        data.year = DOM.yearSelector.value;
        data.calendar_week = DOM.weekSelector.value;
        if (DOM.form.querySelector('#modal_editing_template').value === 'true') {
            data.class_id = DOM.modal.querySelector('#template_class_id').value || '0';
            const templatePromise = new Promise((resolve) => {
                const updatedEntries = state.currentTemplateEditorData.filter(e => {
                    if (blockId && e.block_ref === blockId) return false;
                    if (entryId && e.template_entry_id == entryId) return false;
                    return true;
                });
                const newBlockId = (startPeriod !== endPeriod) ? (blockId || `temp_block_${Date.now()}`) : null;
                for (let p = startPeriod; p <= endPeriod; p++) {
                    updatedEntries.push({
                        day_of_week: data.day_of_week,
                        period_number: p,
                        class_id: data.class_id,
                        teacher_id: data.teacher_id,
                        subject_id: data.subject_id,
                        room_id: data.room_id,
                        block_ref: newBlockId,
                        comment: data.comment || null
                    });
                }
                updateState({ currentTemplateEditorData: updatedEntries });
                resolve({ success: true, message: 'Template-Daten im State aktualisiert' });
            });
            promise = templatePromise;
        } else {
            data.class_id = state.editingClassId;
            if (!data.class_id) {
                showToast("Fehler: Konnte Klasse nicht ermitteln.", 'error');
                return;
            }
            promise = saveEntry(data);
        }
    }
    try {
        const response = await promise;
        if (response.success) {
            showToast("Änderungen erfolgreich gespeichert.", 'success');
            closeModal();
            if (DOM.form.querySelector('#modal_editing_template').value === 'true') {
                renderTimetableGrid(getState(), true);
            } else {
                loadPlanData();
       }
        }
    } catch (error) {
    }
}
async function handleDeleteEntry() {
    let confirmMsg;
    const state = getState();
    const promises = [];
    const substitutionId = DOM.form.querySelector('#substitution_id').value;
    const entryId = DOM.form.querySelector('#entry_id').value;
    const blockId = DOM.form.querySelector('#block_id').value;
    if (state.activeMode === 'substitution') {
        if (!substitutionId) {
            showToast("Fehler: Keine Vertretungs-ID gefunden.", 'error');
            return;
        }
        confirmMsg = 'Soll diese Vertretung gelöscht werden? (Die reguläre Stunde bleibt erhalten)';
        if (await showConfirm("Löschen bestätigen", confirmMsg)) {
            promises.push(deleteSubstitution(substitutionId)); // <-- RICHTIGE STELLE (NEU)
            try {
                const responses = await Promise.all(promises);
                if (responses.every(r => r && r.success)) {
                    showToast("Vertretung erfolgreich gelöscht.", 'success');
                    closeModal();
                    loadPlanData();
                } else {
                    throw new Error("Eintrag konnte nicht gelöscht werden.");
                }
            } catch (error) {  }
        }
    } else {
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
                    loadPlanData();
                }
            } catch (error) {  }
     }
    }
}