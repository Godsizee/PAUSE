import { loadInitialData } from './planer-api.js';
import { initializePageActionHandlers } from './planer-interactions-1.js';
import { initializeEntryClickHandlers } from './planer-interactions-2.js';
import { initializeGridInteractions } from './planer-interactions-3.js';
import { initializeEntryModalInteractions } from './planer-interactions-4.js';
import { initializeActionModals } from './planer-interactions-5.js';
export function initializePlanerDashboard() {
    console.log("planer-dashboard: Initialisiere Planer-Module...");
    initializePageActionHandlers();
    initializeEntryClickHandlers();
    initializeGridInteractions();
    initializeEntryModalInteractions();
    initializeActionModals();
    console.log("planer-dashboard: Lade initiale Daten..."); 
    loadInitialData(); 
}