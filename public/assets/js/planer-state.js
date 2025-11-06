// public/assets/js/planer-state.js
// MODIFIZIERT: Fehlende Funktionen (getState, updateState, etc.) und 
// Status-Properties (selection, dragData etc.) hinzugefügt.
// 'state' ist jetzt eine interne Konstante.
// KORRIGIERT: processTimetableData wandelt Arrays jetzt korrekt in Maps (Objekte) um.

import { timeSlots, days } from './planer-dom.js';

// Globaler Status für den Planer (jetzt intern)
const state = {
    // Stammdaten (wird von loadInitialData gefüllt)
    stammdaten: {
        classes: [],
        teachers: [],
        subjects: [],
        rooms: [],
        absences: [],
        templates: []
    },

    // Aktuelle Ansicht
    currentViewMode: 'class', // 'class' oder 'teacher'
    selectedClassId: null,
    selectedTeacherId: null,
    selectedYear: null,
    selectedWeek: null,
    selectedDate: null, // Für tagesgenaue Vertretungen

    // Geladene Daten (Maps/Objekte)
    timetable: {}, // Wird jetzt als Objekt/Map geführt: { "1-1": [Eintrag1, Eintrag2], "1-2": [Eintrag3] }
    substitutions: {}, // Ebenfalls als Map: { "1-1": [Sub1], "1-2": [] }
    // KORRIGIERT: currentTimetable/currentSubstitutions speichern die flachen Arrays aus der API
    currentTimetable: [],
    currentSubstitutions: [],
    publishStatus: { student: false, teacher: false },

    // UI-Status
    isLoading: false,
    currentModalData: null,
    activeMode: 'regular', // 'regular' oder 'substitution'
    conflictCheckTimeout: null, // Timer für Konfliktprüfung
    lastConflictCheckPromise: null, // Promise für Drag&Drop-Konfliktprüfung
    
    // --- HINZUGEFÜGTE FEHLENDE PROPERTIES (aus interactions-1.js) ---
    selection: {
        start: null, // { day, period, cell, cellData }
        end: null,   // { day, period }
        cells: [],   // [HTMLElement]
    },
    dragData: null, // { type, data, span, originalDay, originalPeriod, ... }
    lastDragOverCell: null,
    editingClassId: null, // Speichert die class_id des Eintrags, der im Modal bearbeitet wird
    currentTemplateEditorData: null, // Speichert die [entries] für den Template-Editor
    currentEditingTemplateId: null, // Speichert die ID der Vorlage, die bearbeitet wird
};

// --- HINZUGEFÜGTE FEHLENDE FUNKTIONEN ---

/**
* Gibt den aktuellen Status zurück.
* @returns {object}
*/
export function getState() {
    return state;
}

/**
* Aktualisiert den Status durch Zusammenführen neuer Daten.
* @param {object} newState - Ein Objekt mit den zu aktualisierenden Schlüsseln.
*/
export function updateState(newState) {
    Object.assign(state, newState);
    // console.log("State updated:", newState, "New state is:", state); // Optional: Debugging
}

/**
* Setzt den Auswahlstatus.
* @param {object} selection - Das neue Auswahl-Objekt.
*/
export function setSelectionState(selection) {
    state.selection = selection;
}

/**
* Setzt den Auswahlstatus zurück und entfernt das visuelle Feedback.
* @param {HTMLElement[]} selectedCells - Array der aktuell ausgewählten Zellen-Elemente.
*/
export function clearSelectionState(selectedCells = []) {
    selectedCells.forEach(cell => cell.classList.remove('selected'));
    state.selection = { start: null, end: null, cells: [] };
}
// --- ENDE HINZUGEFÜGTE FUNKTIONEN ---


/**
 * Initialisiert den Status mit geladenen Stammdaten.
 * (Wird von planer-api.js -> loadInitialData aufgerufen)
 * @param {object} data - Das 'data'-Objekt von der API (classes, teachers, etc.)
 */
export function initializeState(data) {
    // Diese Funktion wird durch updateState({ stammdaten: ... }) in loadInitialData ersetzt.
    // Wir behalten sie zur Referenz, falls sie woanders genutzt wird,
    // aber der Haupt-Ladeprozess nutzt updateState.
    updateState({
        stammdaten: data,
        isLoading: false
    });
}

/**
 * Verarbeitet die geladenen Stundenplan- und Vertretungsdaten (Arrays)
 * und wandelt sie in die Map-Struktur um, die für das Rendering benötigt wird.
 * KORRIGIERT: Speichert Einträge als Arrays in den Maps.
 * @param {object} data - Das 'data'-Objekt von der API (enthält timetable (Array), substitutions (Array), etc.)
 */
export function processTimetableData(data) {
    const newTimetableMap = {};
    const newSubstitutionMap = {};

    // 1. Alle Zellen als leere Arrays initialisieren
    const numPeriods = timeSlots.length; // z.B. 10
    const numDays = days.length; // 5

    for (let day = 1; day <= numDays; day++) {
        for (let period = 1; period <= numPeriods; period++) {
            const cellKey = `${day}-${period}`;
            newTimetableMap[cellKey] = [];
            newSubstitutionMap[cellKey] = [];
        }
    }

    // 2. Reguläre Einträge (Array) in die Map füllen
    if (data.timetable && Array.isArray(data.timetable)) {
        data.timetable.forEach(entry => {
            const cellKey = `${entry.day_of_week}-${entry.period_number}`;
            if (newTimetableMap[cellKey]) {
                // *** KORREKTUR: .push() statt Zuweisung ***
                newTimetableMap[cellKey].push(entry);
            }
        });
    }

    // 3. Vertretungen (Array) in die Map füllen
    if (data.substitutions && Array.isArray(data.substitutions)) {
        data.substitutions.forEach(sub => {
            // 'day_of_week' (1-5) wird vom Repository berechnet
            if (sub.day_of_week) {
                const cellKey = `${sub.day_of_week}-${sub.period_number}`;
                if (newSubstitutionMap[cellKey]) {
                    // *** KORREKTUR: .push() statt Zuweisung ***
                    newSubstitutionMap[cellKey].push(sub);
                }
            }
        });
    }

    // 4. Status aktualisieren (jetzt interne updateState verwenden)
    updateState({
        timetable: newTimetableMap, // Map der regulären Einträge
        substitutions: newSubstitutionMap, // Map der Vertretungen
        // KORREKTUR: Diese Arrays sind die rohen Daten, die wir gerade verarbeitet haben.
        // Wir speichern sie als currentTimetable / currentSubstitutions (flache Arrays)
        currentTimetable: data.timetable || [],
        currentSubstitutions: data.substitutions || [],
        publishStatus: data.publishStatus || { student: false, teacher: false },
        // KORREKTUR: Sicherstellen, dass Abwesenheiten im Stammdaten-Objekt aktualisiert werden
        stammdaten: {
            ...state.stammdaten, // Behalte alte Stammdaten (Klassen, Lehrer etc.)
            absences: data.absences || state.stammdaten.absences || [] // Aktualisiere Abwesenheiten
        },
        isLoading: false
    });
    console.log("planer-state: processTimetableData abgeschlossen. State aktualisiert:", getState());
}

