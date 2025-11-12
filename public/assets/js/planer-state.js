import { timeSlots, days } from './planer-dom.js';
const state = {
    stammdaten: {
        classes: [],
        teachers: [],
        subjects: [],
        rooms: [],
        absences: [], // Wird mit Plandaten geladen
        templates: [] // Wird bei Bedarf geladen
    },
    currentViewMode: 'class', // 'class' oder 'teacher'
    selectedClassId: null,
    selectedTeacherId: null,
    selectedYear: null,
    selectedWeek: null,
    selectedDate: null, // YYYY-MM-DD (für Vertretungen)
    timetable: {}, // Map[cellKey] -> [] (Reguläre Einträge)
    substitutions: {}, // Map[cellKey] -> [] (Vertretungen)
    currentTimetable: [], // Array aller regulären Einträge
    currentSubstitutions: [], // Array aller Vertretungen
    publishStatus: { student: false, teacher: false },
    isLoading: false,
    currentModalData: null, // Daten für das geöffnete Modal
    activeMode: 'regular', // 'regular' oder 'substitution' im Modal
    conflictCheckTimeout: null, // Timeout-ID für debouncing
    lastConflictCheckPromise: null, // Speichert das Promise der letzten Prüfung
    selection: {
        start: null, // { day, period, cell, cellData }
        end: null, // { day, period }
        cells: [], // Array der ausgewählten HTMLElements
    },
    dragData: null, // Daten des gezogenen Elements
    lastDragOverCell: null, // Letzte Zelle, über der geschwebt wurde
    editingClassId: null, // Welche Klassen-ID wird im Modal bearbeitet
    currentTemplateEditorData: null, // Array der Einträge der Vorlage
    currentEditingTemplateId: null, // ID der Vorlage, die bearbeitet wird
};
export function getState() {
    return state;
}
export function updateState(newState) {
    Object.assign(state, newState);
}
export function setSelectionState(selection) {
    state.selection = selection;
}
export function clearSelectionState(selectedCells = []) {
    selectedCells.forEach(cell => cell.classList.remove('selected'));
    state.selection = { start: null, end: null, cells: [] };
}
export function processTimetableData(data) {
    const newTimetableMap = {};
    const newSubstitutionMap = {};
    const numPeriods = timeSlots.length; // z.B. 10
    const numDays = days.length; // z.B. 5
    for (let day = 1; day <= numDays; day++) {
        for (let period = 1; period <= numPeriods; period++) {
            const cellKey = `${day}-${period}`;
            newTimetableMap[cellKey] = [];
            newSubstitutionMap[cellKey] = [];
        }
    }
    if (data.timetable && Array.isArray(data.timetable)) {
        data.timetable.forEach(entry => {
            const cellKey = `${entry.day_of_week}-${entry.period_number}`;
            if (newTimetableMap[cellKey]) {
                newTimetableMap[cellKey].push(entry);
            }
        });
    }
    if (data.substitutions && Array.isArray(data.substitutions)) {
        data.substitutions.forEach(sub => {
            if (sub.day_of_week) {
                const cellKey = `${sub.day_of_week}-${sub.period_number}`;
                if (newSubstitutionMap[cellKey]) {
                    newSubstitutionMap[cellKey].push(sub);
                }
            }
        });
    }
    updateState({
        timetable: newTimetableMap,
        substitutions: newSubstitutionMap,
        currentTimetable: data.timetable || [], // Rohdaten für Drag & Drop
        currentSubstitutions: data.substitutions || [], // Rohdaten für Drag & Drop
        publishStatus: data.publishStatus || { student: false, teacher: false },
        stammdaten: {
            ...state.stammdaten,
            absences: data.absences || state.stammdaten.absences || []
        },
        isLoading: false
    });
}