import { apiFetch } from './api-client.js';
import * as DOM from './planer-dom.js';
import { updateState, getState, processTimetableData } from './planer-state.js';
import { populateAllModalSelects, populateClassSelector, populateTeacherSelector, populateTemplateSelects, updatePublishControls } from './planer-ui.js';
import { renderTimetableGrid } from './planer-timetable.js';
export const loadInitialData = async () => {
    console.log("planer-api: Lade initiale Stammdaten..."); 
    try {
        const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/data`);
        console.log("planer-api: Antwort für Initialdaten:", response); 
        if (response.success && response.data) {
            console.log("planer-api: Initialdaten erfolgreich geladen:", response.data); 
            updateState({
                stammdaten: response.data, 
                templates: response.data.templates || [] 
            });
            populateClassSelector(response.data.classes);
            populateTeacherSelector(response.data.teachers);
            populateAllModalSelects(response.data); 
            populateTemplateSelects(response.data.templates || []); 
            if (DOM.classSelector && DOM.classSelector.options.length > 1) { 
                console.log("planer-api: Setze Standardauswahl auf erste Klasse."); 
                DOM.classSelector.selectedIndex = 1; 
                updateState({ selectedClassId: DOM.classSelector.value });
                await loadPlanData(); // Lädt den Plan für die erste Klasse
            } else {
                 console.log("planer-api: Keine Klassen zum Auswählen gefunden, lade leeren Plan."); 
                 await loadPlanData(); // Lädt leeren Plan (oder den des ersten Lehrers, falls das die Standardansicht wäre)
            }
        } else {
            throw new Error(response.message || "Stammdaten konnten nicht geladen werden oder haben ein unerwartetes Format.");
        }
    } catch (error) {
        console.error("planer-api: Fehler beim Laden der Initialdaten:", error);
        if (DOM.timetableContainer) { 
            DOM.timetableContainer.innerHTML = `<p class="message error">${error.message || 'Stammdaten konnten nicht geladen werden.'}</p>`;
        }
    }
};
export const loadPlanData = async () => {
    console.log("planer-api: Lade Plandaten..."); 
    let { currentViewMode, selectedClassId, selectedTeacherId } = getState();
    selectedClassId = DOM.classSelector ? DOM.classSelector.value : null;
    selectedTeacherId = DOM.teacherSelector ? DOM.teacherSelector.value : null;
    const selectedYear = DOM.yearSelector ? DOM.yearSelector.value : null;
    const selectedWeek = DOM.weekSelector ? DOM.weekSelector.value : null;
    if (!selectedYear || !selectedWeek) {
        console.warn("planer-api: Jahr oder Woche nicht ausgewählt.");
        if(DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte Jahr und Kalenderwoche auswählen.</p>';
        updatePublishControls({ student: false, teacher: false });
        return;
    }
    const cacheBuster = `_t=${new Date().getTime()}`;
    let url = `${window.APP_CONFIG.baseUrl}/api/planer/data?year=${selectedYear}&week=${selectedWeek}&${cacheBuster}`;
    if (currentViewMode === 'class') {
        if (!selectedClassId) {
            console.log("planer-api: Keine Klasse ausgewählt, breche Plandaten-Ladevorgang ab."); 
            if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte einen Lehrer oder eine Klasse auswählen.</p>';
            updatePublishControls({ student: false, teacher: false }); 
            updateState({ selectedClassId: null, selectedTeacherId: null, currentTimetable: {}, currentSubstitutions: {}, currentPublishStatus: { student: false, teacher: false } });
            return;
        }
        updateState({ selectedClassId: selectedClassId, selectedTeacherId: null });
        url += `&class_id=${selectedClassId}`;
    } else { // currentViewMode === 'teacher'
        if (!selectedTeacherId) {
             console.log("planer-api: Kein Lehrer ausgewählt, breche Plandaten-Ladevorgang ab."); 
            if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte einen Lehrer oder eine Klasse auswählen.</p>';
            updatePublishControls({ student: false, teacher: false });
            updateState({ selectedClassId: null, selectedTeacherId: null, currentTimetable: {}, currentSubstitutions: {}, currentPublishStatus: { student: false, teacher: false } });
            return;
        }
        updateState({ selectedClassId: null, selectedTeacherId: selectedTeacherId });
        url += `&teacher_id=${selectedTeacherId}`;
    }
    if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<div class="loading-spinner"></div>';
    try {
        const response = await apiFetch(url);
        console.log("planer-api: Antwort für Plandaten:", response); 
        if (response.success && response.data) {
             console.log("planer-api: Plandaten erfolgreich geladen, rufe processTimetableData auf...", response.data); 
             processTimetableData(response.data);
             renderTimetableGrid(); 
             updatePublishControls(getState().currentPublishStatus);
        } else {
            throw new Error(response.message || "Plandaten konnten nicht geladen werden.");
        }
    } catch (error) {
        console.error("planer-api: Fehler beim Laden der Plandaten:", error);
         if (DOM.timetableContainer) { 
             DOM.timetableContainer.innerHTML = `<p class="message error">${error.message || 'Stundenplan konnte nicht geladen werden.'}</p>`;
         }
        updatePublishControls({ student: false, teacher: false });
    }
};
export const publishWeek = async (target, publish = true) => {
    const currentYear = DOM.yearSelector.value; 
    const currentWeek = DOM.weekSelector.value; 
    if (!currentYear || !currentWeek) {
        window.showToast("Bitte Jahr und KW auswählen.", 'error');
        throw new Error("Jahr oder Woche nicht ausgewählt."); 
    }
    const url = publish ? `${window.APP_CONFIG.baseUrl}/api/planer/publish` : `${window.APP_CONFIG.baseUrl}/api/planer/unpublish`;
    const body = JSON.stringify({ year: currentYear, week: currentWeek, target });
    return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
};
export const checkConflicts = async (data) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/check-conflicts`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(data)
     });
};
export const saveEntry = async (data) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/entry/save`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(data)
     });
};
export const deleteEntry = async (body) => {
    const url = `${window.APP_CONFIG.baseUrl}/api/planer/entry/delete`;
    return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
};
export const saveSubstitution = async (data) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/substitution/save`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(data)
     });
};
export const deleteSubstitution = async (id) => {
    const url = `${window.APP_CONFIG.baseUrl}/api/planer/substitution/delete`;
     return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ substitution_id: id }) });
};
export const copyWeek = async (body) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/copy-week`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(body)
     });
};
export const loadTemplates = async () => {
    try {
        const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates`);
        if (response.success) {
            const templates = response.data || [];
            updateState({ templates: templates });
            populateTemplateSelects(templates); 
            return templates;
        } else {
            throw new Error(response.message || "Vorlagen konnten nicht geladen werden.");
        }
    } catch (error) {
        updateState({ templates: [] }); 
        console.error("Fehler beim Laden der Vorlagen:", error);
        if (DOM.applyTemplateSelect) DOM.applyTemplateSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
        if (DOM.manageTemplatesList) DOM.manageTemplatesList.innerHTML = '<p class="message error">Fehler beim Laden.</p>';
        return []; 
    }
};
export const createTemplate = async (body) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/create`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(body)
     });
};
export const applyTemplate = async (body) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/apply`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(body)
     });
};
export const deleteTemplate = async (templateId) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/delete`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify({ templateId: templateId })
     });
};
export const loadTemplateDetails = async (templateId) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/${templateId}`);
};
export const saveTemplate = async (templateData) => {
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/save`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(templateData)
     });
};