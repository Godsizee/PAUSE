import * as DOM from './planer-dom.js';
import { getState, updateState } from './planer-state.js';
import { getWeekAndYear } from './planer-utils.js';
import { loadPlanData, publishWeek } from './planer-api.js';
import { populateYearSelector, populateWeekSelector, updatePublishControls } from './planer-ui.js';
import { showToast } from './notifications.js';

const WEEK_SELECTION_KEY = 'planer_week_selection';

export function initializePageActionHandlers() {
    setDefaultSelectors();

    DOM.viewModeSelector.addEventListener('change', handleViewModeChange);
    DOM.classSelector.addEventListener('change', () => loadPlanData());
    DOM.teacherSelector.addEventListener('change', () => loadPlanData());
    DOM.yearSelector.addEventListener('change', () => {
        saveSelection();
        loadPlanData();
    });
    DOM.weekSelector.addEventListener('change', () => {
        saveSelection();
        loadPlanData();
    });

    DOM.publishStudentBtn.addEventListener('click', () => handlePublishAction('student', true));
    DOM.unpublishStudentBtn.addEventListener('click', () => handlePublishAction('student', false));
    DOM.publishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', true));
    DOM.unpublishTeacherBtn.addEventListener('click', () => handlePublishAction('teacher', false));
}

function saveSelection() {
    try {
        const selection = {
            year: DOM.yearSelector.value,
            week: DOM.weekSelector.value
        };
        localStorage.setItem(WEEK_SELECTION_KEY, JSON.stringify(selection));
    } catch (e) {
        console.warn("Speichern der Wochenauswahl im localStorage fehlgeschlagen:", e);
    }
}

export function setDefaultSelectors() {
    let defaultYear, defaultWeek;
    try {
        const savedSelection = localStorage.getItem(WEEK_SELECTION_KEY);
        if (savedSelection) {
            const parsed = JSON.parse(savedSelection);
            if (parsed.year && parsed.week) {
                defaultYear = parseInt(parsed.year, 10);
                defaultWeek = parseInt(parsed.week, 10);
            }
        }
    } catch (e) {
        console.warn("Laden der Wochenauswahl aus localStorage fehlgeschlagen:", e);
    }

    if (!defaultYear || !defaultWeek) {
        const today = new Date();
        const { week, year } = getWeekAndYear(today);
        defaultYear = year;
        defaultWeek = week;
        saveSelection(); 
    }

    populateYearSelector(DOM.yearSelector, defaultYear);
    populateWeekSelector(DOM.weekSelector, defaultWeek);
}

function handleViewModeChange() {
    updateState({ currentViewMode: DOM.viewModeSelector.value });
    if (getState().currentViewMode === 'class') {
        DOM.classSelectorContainer.classList.remove('hidden');
        DOM.teacherSelectorContainer.classList.add('hidden');
        DOM.teacherSelector.value = ''; // Auswahl zurücksetzen
    } else {
        DOM.classSelectorContainer.classList.add('hidden');
        DOM.teacherSelectorContainer.classList.remove('hidden');
        DOM.classSelector.value = ''; // Auswahl zurücksetzen
    }
    loadPlanData(); // Plandaten für die neue Ansicht laden
}

async function handlePublishAction(target, publish = true) {
    const year = DOM.yearSelector.value;
    const week = DOM.weekSelector.value;
    if (!year || !week) {
        showToast("Bitte Jahr und KW auswählen.", 'error');
        throw new Error("Jahr oder Woche nicht ausgewählt."); 
    }

    try {
        const response = await publishWeek(target, publish); 

        // KORREKTUR: Die Antwort der API (die den neuen Status enthält) direkt verwenden.
        if (response.success && response.data && response.data.publishStatus) {
            showToast(response.message, 'success');
            // 1. Globalen Status aktualisieren
            updateState({ currentPublishStatus: response.data.publishStatus });
            // 2. UI-Komponenten (Buttons, Status-Text) aktualisieren
            updatePublishControls(getState().currentPublishStatus);
        } else {
            // Fallback oder Fehler, falls die Antwort nicht wie erwartet aussieht
            showToast("Aktion erfolgreich, aber Status konnte nicht aktualisiert werden. Lade neu...", 'info');
            await loadPlanData(); // Fallback: Neu laden
        }
    } catch(error) {
        console.error(`Fehler bei Aktion '${publish ? 'publish' : 'unpublish'}' für '${target}':`, error);
    }
}