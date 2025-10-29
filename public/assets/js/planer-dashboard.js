// public/assets/js/planer-dashboard.js
import { initializePlanerInteractions } from './planer-interactions-2.js'; // Importiere Teil 2
import { loadInitialData } from './planer-api.js';

/**
 * Initialisiert das Planer-Dashboard.
 */
export function initializePlanerDashboard() {
    console.log("planer-dashboard: Initialisiere Planer Interaktionen..."); // Log hinzugefügt
    // 1. Alle Event-Listener und Interaktionslogik initialisieren (jetzt in Teil 2)
    initializePlanerInteractions();

    console.log("planer-dashboard: Lade initiale Daten..."); // Log hinzugefügt
    // 2. Die initialen Stammdaten laden (was wiederum das Laden des Plans auslöst)
    loadInitialData(); // Aufruf wieder aktiviert
}
