// --- State Management ---
let state = {
    stammdaten: {}, 
    currentTimetable: [], 
    currentSubstitutions: [],
    currentViewMode: 'class', 
    selectedClassId: null, 
    selectedTeacherId: null,
    selectedDate: null, 
    activeMode: 'regular',
    selection: { start: null, end: null, cells: [] },
    currentPublishStatus: { student: false, teacher: false },
    conflictCheckTimeout: null, // Für Debouncing
    dragData: null, // Für Drag & Drop
    lastDragOverCell: null, // Für Drag & Drop
    lastConflictCheckPromise: null, // Für Drag & Drop
    editingClassId: null, // Speichert die Klasse, die im Modal bearbeitet wird
    templates: [], // Array für geladene Vorlagen
    currentTemplateEditorData: null, // NEU: Daten für den Template-Editor
    currentEditingTemplateId: null // NEU: ID der Vorlage, die bearbeitet wird
};

/**
 * Gibt eine Kopie des aktuellen Zustands zurück.
 * @returns {object} Der aktuelle Zustand.
 */
export function getState() {
    // Gibt eine Kopie zurück, um direkte Mutationen zu verhindern (optional, aber gute Praxis)
    return { ...state };
}

/**
 * Aktualisiert Teile des Zustands.
 * @param {object} newState - Ein Objekt mit den zu aktualisierenden Schlüsseln.
 */
export function updateState(newState) {
    state = { ...state, ...newState };
}

/**
 * Setzt den Auswahlstatus zurück.
 * @param {HTMLElement[]} cells - Die Zellen, deren 'selected'-Klasse entfernt werden soll.
 */
export function clearSelectionState(cells) {
    cells.forEach(cell => cell.classList.remove('selected'));
    state.selection = { start: null, end: null, cells: [] };
}

/**
 * Setzt die Auswahl im Status.
 * @param {object} newSelection - Das neue Auswahl-Objekt.
 */
export function setSelectionState(newSelection) {
    state.selection = newSelection;
}
