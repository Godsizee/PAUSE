import * as DOM from './planer-dom.js';
import { getState, updateState, clearSelectionState, setSelectionState } from './planer-state.js';
import { openModal } from './planer-interactions-4.js';
export function initializeEntryClickHandlers() {
    DOM.timetableContainer.addEventListener('click', (e) => {
        const cell = e.target.closest('.grid-cell');
        const state = getState();
        if (!cell || !(state.selectedClassId || state.selectedTeacherId)) {
            clearSelectionState(state.selection.cells); 
            return;
        }
        handleCellClick(cell, DOM.timetableContainer);
        if (e.detail === 2) {
            openModal(e, false); // false = kein Template-Editor
        }
    });
}
export function handleCellClick(cell, container) {
    if (!container) {
        container = DOM.timetableContainer;
    }
    const clickedDay = cell.dataset.day;
    const clickedPeriod = parseInt(cell.dataset.period);
    const state = getState();
    const firstEntryInCell = cell.querySelector('.planner-entry');
    const cellData = firstEntryInCell ? firstEntryInCell.dataset : {};
    const startCellData = state.selection.start ? state.selection.start.cellData : {};
    const isSameGroupAsStart = (startCellData.blockId && cellData.blockId === startCellData.blockId) ||
                                (startCellData.entryId && cellData.entryId === startCellData.entryId) ||
                                (startCellData.substitutionId && cellData.substitutionId === startCellData.substitutionId);
    const startIsEmpty = !startCellData.entryId && !startCellData.blockId && !startCellData.substitutionId;
    const currentIsEmpty = !cellData.entryId && !cellData.blockId && !cellData.substitutionId;
    if (
        !state.selection.start ||
        clickedDay !== state.selection.start.day ||
        cell.classList.contains('default-entry') ||
        (!isSameGroupAsStart && !(startIsEmpty && currentIsEmpty))
    ) {
        clearSelectionState(state.selection.cells);
        setSelectionState({ start: { day: clickedDay, period: clickedPeriod, cell: cell, cellData: cellData }, end: null, cells: [cell] });
        cell.classList.add('selected');
        return;
    }
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
    state.selection.start.cell = newSelectionCells[0]; // Startzelle aktualisieren (falls rückwärts ausgewählt wurde)
    state.selection.start.period = parseInt(newSelectionCells[0].dataset.period);
    setSelectionState(state.selection);
}