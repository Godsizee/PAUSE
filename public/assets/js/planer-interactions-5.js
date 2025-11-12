import * as DOM from './planer-dom.js';
import { getState, updateState } from './planer-state.js';
import {
    copyWeek, loadTemplates, createTemplate, applyTemplate, deleteTemplate,
    loadTemplateDetails, saveTemplate, loadPlanData
} from './planer-api.js';
import { populateYearSelector, populateWeekSelector, populateTemplateSelects, showTemplateView, populateAllModalSelects } from './planer-ui.js';
import { renderTimetableGrid } from './planer-timetable.js';
import { showToast, showConfirm } from './notifications.js';
import { getWeekAndYear, getDateOfISOWeek, escapeHtml } from './planer-utils.js';
export function initializeActionModals() {
    DOM.copyWeekBtn.addEventListener('click', openCopyModal);
    DOM.copyWeekCancelBtn.addEventListener('click', closeCopyModal);
    DOM.copyWeekModal.addEventListener('click', (e) => { if (e.target.id === 'copy-week-modal') closeCopyModal(); });
    DOM.copyWeekForm.addEventListener('submit', handleCopyWeekSubmit);
    DOM.createTemplateBtn.addEventListener('click', handleCreateTemplateFromWeek);
    DOM.applyTemplateBtn.addEventListener('click', openApplyTemplateModal);
    DOM.manageTemplatesBtn.addEventListener('click', openManageTemplatesModal);
    DOM.manageTemplatesCloseBtn.addEventListener('click', closeManageTemplatesModal);
    DOM.applyTemplateCancelBtn.addEventListener('click', closeApplyTemplateModal);
    DOM.manageTemplatesModal.addEventListener('click', (e) => { if (e.target.id === 'manage-templates-modal') closeManageTemplatesModal(); });
    DOM.applyTemplateModal.addEventListener('click', (e) => { if (e.target.id === 'apply-template-modal') closeApplyTemplateModal(); });
    DOM.manageTemplatesForm.addEventListener('submit', handleCreateTemplateSubmit);
    DOM.applyTemplateForm.addEventListener('submit', handleApplyTemplateSubmit);
    DOM.createEmptyTemplateBtn.addEventListener('click', handleCreateEmptyTemplate);
    DOM.backToTemplateListBtn.addEventListener('click', handleBackToTemplateList);
    DOM.saveTemplateEditorBtn.addEventListener('click', handleSaveTemplateEditor);
}
function openCopyModal() {
    const state = getState();
    if (!state.selectedClassId && !state.selectedTeacherId) {
        showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen.", 'error');
        return;
    }
    DOM.copySourceDisplay.value = `KW ${DOM.weekSelector.value} / ${DOM.yearSelector.value}`;
    const currentMonday = getDateOfISOWeek(parseInt(DOM.weekSelector.value), parseInt(DOM.yearSelector.value));
    const nextWeekDate = new Date(currentMonday.getTime() + 7 * 24 * 60 * 60 * 1000);
    const { week: nextWeek, year: nextYear } = getWeekAndYear(nextWeekDate);
    populateYearSelector(DOM.copyTargetYear, nextYear);
    populateWeekSelector(DOM.copyTargetWeek, nextWeek);
    DOM.copyWeekModal.classList.add('visible');
}
function closeCopyModal() {
    DOM.copyWeekModal.classList.remove('visible');
}
async function handleCopyWeekSubmit(e) {
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
        : `Lehrer ${DOM.teacherSelector.options[DOM.teacherSelector.selectedIndex]?.text || state.selectedTeacherId}`;
    if (await showConfirm("Kopieren bestätigen", `Sind Sie sicher, dass Sie den Plan für '${escapeHtml(entityName)}' von KW ${sourceWeek}/${sourceYear} nach KW ${targetWeek}/${targetYear} kopieren möchten? Alle Einträge in der Zielwoche werden überschrieben.`)) {
        const body = { sourceYear, sourceWeek, targetYear, targetWeek, classId: state.selectedClassId, teacherId: state.selectedTeacherId };
        try {
            const response = await copyWeek(body);
            if (response.success) {
                showToast(response.message, 'success');
                closeCopyModal();
                DOM.yearSelector.value = targetYear;
                DOM.weekSelector.value = targetWeek;
                loadPlanData();
            }
        } catch (error) {  }
    }
}
async function openManageTemplatesModal() {
    DOM.manageTemplatesForm.reset();
    const templates = await loadTemplates(); // Lädt Vorlagen und füllt Selects
    renderTemplatesList(templates); // Rendert die Liste im "Verwalten"-Modal
    showTemplateView('list'); // Zeigt die Listenansicht
    DOM.manageTemplatesModal.classList.add('visible');
}
function closeManageTemplatesModal() {
    DOM.manageTemplatesModal.classList.remove('visible');
}
async function openApplyTemplateModal() {
    const state = getState();
    if (!state.selectedClassId && !state.selectedTeacherId) {
        showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen.", 'error');
        return;
    }
    await loadTemplates(); // Füllt automatisch das Select-Feld
    DOM.applyTemplateForm.reset();
    DOM.applyTemplateModal.classList.add('visible');
}
function closeApplyTemplateModal() {
    DOM.applyTemplateModal.classList.remove('visible');
}
function handleCreateTemplateFromWeek() {
    const state = getState();
    if (!state.selectedClassId && !state.selectedTeacherId) {
        showToast("Bitte zuerst eine Klasse oder einen Lehrer auswählen, um eine Vorlage zu erstellen.", 'error');
        return;
    }
    if (state.currentTimetable.length === 0) {
        showToast("Die aktuelle Woche enthält keine Einträge zum Speichern als Vorlage.", 'info');
        return;
    }
    openManageTemplatesModal();
}
async function handleCreateTemplateSubmit(e) {
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
            const templates = await loadTemplates(); // Lädt neu und aktualisiert alle Selects
            renderTemplatesList(templates); // Rendert die Liste im Modal neu
        }
    } catch (error) {  }
}
async function handleApplyTemplateSubmit(e) {
    e.preventDefault();
    const templateId = DOM.applyTemplateSelect.value;
    if (!templateId) {
        showToast("Bitte wählen Sie eine Vorlage aus.", 'error');
        return;
    }
    const targetYear = DOM.yearSelector.value;
    const targetWeek = DOM.weekSelector.value;
    if (!targetYear || !targetWeek) return; 
    const state = getState();
    if (await showConfirm("Vorlage anwenden", "Sind Sie sicher? Alle Einträge für die aktuelle Auswahl in dieser Woche werden durch die Vorlage überschrieben.")) {
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
                loadPlanData(); 
            }
        } catch (error) {  }
    }
}
function handleCreateEmptyTemplate() {
    updateState({ 
        currentTemplateEditorData: [], // Leere Daten
        currentEditingTemplateId: null // Keine ID
    }); 
    DOM.templateEditorTitle.textContent = 'Neue leere Vorlage erstellen';
    const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
    const descInput = DOM.manageTemplatesModal.querySelector('#template-editor-description');
    if(nameInput) nameInput.value = '';
    if(descInput) descInput.value = '';
    populateAllModalSelects(getState().stammdaten);
    renderTimetableGrid(getState(), true); // true = Template-Editor-Modus
    showTemplateView('editor');
}
function handleBackToTemplateList() {
    showTemplateView('list');
    updateState({ 
        currentTemplateEditorData: null, 
        currentEditingTemplateId: null 
    }); 
}
async function handleSaveTemplateEditor() {
    const state = getState();
    const name = DOM.manageTemplatesModal.querySelector('#template-editor-name').value.trim();
    const description = DOM.manageTemplatesModal.querySelector('#template-editor-description').value.trim();
    const templateId = state.currentEditingTemplateId; // Kann null sein (für neue Vorlage)
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
    } catch (error) {  }
}
export function renderTemplatesList(templates) {
    if (!DOM.manageTemplatesList) return;
    if (!Array.isArray(templates)) {
        console.error("renderTemplatesList: Input 'templates' ist kein Array.", templates);
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
    DOM.manageTemplatesList.querySelectorAll('.delete-template').forEach(button => {
        button.addEventListener('click', handleDeleteTemplateClick);
    });
    DOM.manageTemplatesList.querySelectorAll('.edit-template').forEach(button => {
        button.addEventListener('click', handleEditTemplateClick);
    });
}
async function handleEditTemplateClick(e) {
    const button = e.target;
    const templateId = button.dataset.id;
    if (!templateId) return;
    try {
        const response = await loadTemplateDetails(templateId);
        if (response.success && response.data) {
            const { template, entries } = response.data;
            updateState({
                currentTemplateEditorData: entries || [],
                currentEditingTemplateId: template.template_id
            });
            DOM.templateEditorTitle.textContent = `Vorlage bearbeiten: ${escapeHtml(template.name)}`;
            const nameInput = DOM.manageTemplatesModal.querySelector('#template-editor-name');
            const descInput = DOM.manageTemplatesModal.querySelector('#template-editor-description');
            if (nameInput) nameInput.value = template.name;
            if (descInput) descInput.value = template.description || '';
            renderTimetableGrid(getState(), true); // true = Template-Editor-Modus
            showTemplateView('editor');
        }
    } catch (error) {  }
}
async function handleDeleteTemplateClick(e) {
    const button = e.target;
    const row = button.closest('tr');
    if (!row) return; 
    const templateId = row.dataset.id;
    const templateName = button.dataset.name;
    if (await showConfirm("Vorlage löschen", `Sind Sie sicher, dass Sie die Vorlage "${templateName}" endgültig löschen möchten?`)) {
        try {
            const response = await deleteTemplate(templateId);
            if (response.success) {
                showToast(response.message, 'success');
                const updatedTemplates = await loadTemplates(); // Lädt neu und aktualisiert Selects
                renderTemplatesList(updatedTemplates); // Rendert Liste im Modal neu
            }
        } catch (error) {  }
    }
}