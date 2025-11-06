import { apiFetch } from './api-client.js';
 import * as DOM from './planer-dom.js';
 // KORRIGIERT: processTimetableData importiert
 import { updateState, getState, processTimetableData } from './planer-state.js';
 import { populateAllModalSelects, populateClassSelector, populateTeacherSelector, populateTemplateSelects, updatePublishControls } from './planer-ui.js';
 // KORREKTUR 1: Import auf 'renderTimetableGrid' geändert
 import { renderTimetableGrid } from './planer-timetable.js';
 // renderTemplatesList wird in planer-interactions-2.js importiert und dort nach dem API-Aufruf verwendet.
 // import { renderTemplatesList } from './planer-interactions-2.js';
 
 /** Lädt Stammdaten (Klassen, Lehrer, etc.) UND VORLAGEN */
 export const loadInitialData = async () => {
     console.log("planer-api: Lade initiale Stammdaten..."); // Logging
     try {
         // Fetch base data including templates
         // The initial call to /api/planer/data without class_id/teacher_id should return base data
         // KORREKTUR: API-Aufruf, um sicherzustellen, dass 'absences' enthalten sind
         const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/data`);
         console.log("planer-api: Antwort für Initialdaten:", response); // Logging
         if (response.success && response.data) {
             console.log("planer-api: Initialdaten erfolgreich geladen:", response.data); // Logging
             updateState({
                 // Ensure stammdaten contains the base data (classes, teachers etc.)
                 stammdaten: response.data, // Speichere ALLE Stammdaten, inkl. 'absences'
                 templates: response.data.templates || [] // Ensure templates array exists
             });
             // UI-Updates
             // Pass the specific arrays to the populating functions
             populateClassSelector(response.data.classes);
             populateTeacherSelector(response.data.teachers);
             populateAllModalSelects(response.data); // Populates modal selects using the full data object
             populateTemplateSelects(response.data.templates || []); // Populates template selects in modals
 
             // Setzt die Vorauswahl und lädt den ersten Plan
             if (DOM.classSelector && DOM.classSelector.options.length > 1) { // Check if classes were loaded and selector exists
                 console.log("planer-api: Setze Standardauswahl auf erste Klasse."); // Logging
                 DOM.classSelector.selectedIndex = 1; // Select the first actual class
                 updateState({ selectedClassId: DOM.classSelector.value });
                 await loadPlanData(); // Load plan for the initially selected class
             } else {
                  console.log("planer-api: Keine Klassen zum Auswählen gefunden, lade leeren Plan."); // Logging
                  await loadPlanData(); // Attempt to load plan data even if no class selected (will show message)
             }
         } else {
             // Throw error if API call failed or data format is unexpected
             throw new Error(response.message || "Stammdaten konnten nicht geladen werden oder haben ein unerwartetes Format.");
         }
     } catch (error) {
         console.error("planer-api: Fehler beim Laden der Initialdaten:", error);
         if (DOM.timetableContainer) { // Check if container exists before updating
             DOM.timetableContainer.innerHTML = `<p class="message error">${error.message || 'Stammdaten konnten nicht geladen werden.'}</p>`;
         }
         // Optionally disable UI elements if initial load fails
     }
 };
 
 
 /** Lädt die Plandaten für die ausgewählte Ansicht/Woche */
 export const loadPlanData = async () => {
     console.log("planer-api: Lade Plandaten..."); // Logging hinzugefügt
     let { currentViewMode, selectedClassId, selectedTeacherId } = getState();
     // Re-read selected values directly from DOM elements as they are the source of truth
     selectedClassId = DOM.classSelector ? DOM.classSelector.value : null;
     selectedTeacherId = DOM.teacherSelector ? DOM.teacherSelector.value : null;
 
     const selectedYear = DOM.yearSelector ? DOM.yearSelector.value : null;
     const selectedWeek = DOM.weekSelector ? DOM.weekSelector.value : null;
 
     // Check if selectors exist and have values before proceeding
      if (!selectedYear || !selectedWeek) {
          console.warn("planer-api: Jahr oder Woche nicht ausgewählt.");
          if(DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte Jahr und Kalenderwoche auswählen.</p>';
          updatePublishControls({ student: false, teacher: false });
          return;
      }
 
     // Base URL for the API endpoint
     let url = `${window.APP_CONFIG.baseUrl}/api/planer/data?year=${selectedYear}&week=${selectedWeek}`;
 
     // Append class_id or teacher_id based on the current view mode
     if (currentViewMode === 'class') {
         if (!selectedClassId) {
             console.log("planer-api: Keine Klasse ausgewählt, breche Plandaten-Ladevorgang ab."); // Logging hinzugefügt
             // KORREKTUR 3: Generische Meldung
             if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte einen Lehrer oder eine Klasse auswählen.</p>';
             updatePublishControls({ student: false, teacher: false }); // Reset publish status display
             // Clear relevant state parts but keep stammdaten
             // KORRIGIERT: timetable und substitutions auf leere OBJEKTE setzen
             updateState({ selectedClassId: null, selectedTeacherId: null, currentTimetable: {}, currentSubstitutions: {}, currentPublishStatus: { student: false, teacher: false } });
             return;
         }
         // Update state and URL for class view
         updateState({ selectedClassId: selectedClassId, selectedTeacherId: null });
         url += `&class_id=${selectedClassId}`;
     } else { // Teacher view
         if (!selectedTeacherId) {
              console.log("planer-api: Kein Lehrer ausgewählt, breche Plandaten-Ladevorgang ab."); // Logging hinzugefügt
             // KORREKTUR 3: Generische Meldung
             if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<p class="message info">Bitte einen Lehrer oder eine Klasse auswählen.</p>';
             updatePublishControls({ student: false, teacher: false });
              // Clear relevant state parts but keep stammdaten
              // KORRIGIERT: timetable und substitutions auf leere OBJEKTE setzen
             updateState({ selectedClassId: null, selectedTeacherId: null, currentTimetable: {}, currentSubstitutions: {}, currentPublishStatus: { student: false, teacher: false } });
             return;
         }
         // Update state and URL for teacher view
         updateState({ selectedClassId: null, selectedTeacherId: selectedTeacherId });
         url += `&teacher_id=${selectedTeacherId}`;
     }
 
     // Show loading spinner while fetching data
     if (DOM.timetableContainer) DOM.timetableContainer.innerHTML = '<div class="loading-spinner"></div>';
     try {
         // Fetch timetable data from the API
         const response = await apiFetch(url);
         console.log("planer-api: Antwort für Plandaten:", response); // Logging hinzugefügt
         if (response.success && response.data) {
              console.log("planer-api: Plandaten erfolgreich geladen, rufe processTimetableData auf...", response.data); // Logging hinzugefügt
 
              // *** KORREKTUR: Rufe processTimetableData auf, um Maps zu erstellen ***
              processTimetableData(response.data);
              // *** ENDE KORREKTUR ***
 
             // Render the timetable grid with the new data
             // KORREKTUR 1: Aufruf an 'renderTimetableGrid' geändert
             renderTimetableGrid(); // <--- HIER WIRD GERENDERT (getState() wird intern geholt)
             // Update the publish control buttons based on the fetched status
             updatePublishControls(getState().currentPublishStatus);
         } else {
             // If API call was not successful, throw an error
             throw new Error(response.message || "Plandaten konnten nicht geladen werden.");
         }
     } catch (error) {
         // Log error and display an error message in the timetable container
         console.error("planer-api: Fehler beim Laden der Plandaten:", error);
          if (DOM.timetableContainer) { // Check if container exists
              DOM.timetableContainer.innerHTML = `<p class="message error">${error.message || 'Stundenplan konnte nicht geladen werden.'}</p>`;
          }
         // Reset publish controls in case of error
         updatePublishControls({ student: false, teacher: false });
     }
 };
 
 /** API-Aufruf zum Veröffentlichen/Zurückziehen */
 export const publishWeek = async (target, publish = true) => {
     // const { year, week } = getState(); // Get current year/week from state // This might be wrong if selectors changed
     const currentYear = DOM.yearSelector.value; // Get selected year from DOM
     const currentWeek = DOM.weekSelector.value; // Get selected week from DOM
     if (!currentYear || !currentWeek) {
         window.showToast("Bitte Jahr und KW auswählen.", 'error');
         throw new Error("Jahr oder Woche nicht ausgewählt."); // Prevent API call
     }
     const url = publish ? `${window.APP_CONFIG.baseUrl}/api/planer/publish` : `${window.APP_CONFIG.baseUrl}/api/planer/unpublish`;
     const body = JSON.stringify({ year: currentYear, week: currentWeek, target });
     // Make the API call using apiFetch
     return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
 };
 
 /** API-Aufruf zur Konfliktprüfung */
 export const checkConflicts = async (data) => {
      // Make the API call to check for conflicts
     return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/check-conflicts`, {
         method: 'POST',
         headers: { 'Content-Type': 'application/json' },
         body: JSON.stringify(data)
     });
 };
 
 /** API-Aufruf zum Speichern eines Eintrags */
 export const saveEntry = async (data) => {
       // Make the API call to save a regular timetable entry or block
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/entry/save`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
      });
 };
 
 /** API-Aufruf zum Löschen eines Eintrags/Blocks */
 export const deleteEntry = async (body) => {
     // Body should contain { entry_id: id } or { block_id: id }
     const url = `${window.APP_CONFIG.baseUrl}/api/planer/entry/delete`;
     // Make the API call to delete an entry or block
     return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
 };
 
 /** API-Aufruf zum Speichern einer Vertretung */
 export const saveSubstitution = async (data) => {
       // Make the API call to save a substitution entry
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/substitution/save`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
      });
 };
 
 /** API-Aufruf zum Löschen einer Vertretung */
 export const deleteSubstitution = async (id) => {
     const url = `${window.APP_CONFIG.baseUrl}/api/planer/substitution/delete`;
      // Make the API call to delete a substitution entry
      return await apiFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ substitution_id: id }) });
 };
 
 /** API-Aufruf zum Kopieren einer Woche */
 export const copyWeek = async (body) => {
       // Make the API call to copy timetable data from one week to another
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/copy-week`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
      });
 };
 
 /** API-Aufruf zum Laden aller Vorlagen - returns data instead of calling render */
 export const loadTemplates = async () => {
     try {
         // Fetch the list of available templates
         const response = await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates`);
         if (response.success) {
             const templates = response.data || [];
             // Update state and UI selects
             updateState({ templates: templates });
             populateTemplateSelects(templates); // Update selects in modals
             // Return the data for the caller to handle rendering the list
             return templates;
         } else {
             throw new Error(response.message || "Vorlagen konnten nicht geladen werden.");
         }
     } catch (error) {
         // Handle errors during template loading
         updateState({ templates: [] }); // Clear templates in state
         console.error("Fehler beim Laden der Vorlagen:", error);
         // Display error messages in relevant UI elements
         if (DOM.applyTemplateSelect) DOM.applyTemplateSelect.innerHTML = '<option value="">Fehler beim Laden</option>';
         if (DOM.manageTemplatesList) DOM.manageTemplatesList.innerHTML = '<p class="message error">Fehler beim Laden.</p>';
         return []; // Return empty array on error
     }
 };
 
 
 /** API-Aufruf zum Erstellen einer Vorlage */
 export const createTemplate = async (body) => {
       // Make the API call to create a new template
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/create`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
      });
 };
 
 /** API-Aufruf zum Anwenden einer Vorlage */
 export const applyTemplate = async (body) => {
       // Make the API call to apply a template to a specific week
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/apply`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body)
      });
 };
 
 /** API-Aufruf zum Löschen einer Vorlage */
 export const deleteTemplate = async (templateId) => {
       // Make the API call to delete a template
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/delete`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ templateId: templateId })
      });
 };
 
 // --- NEUE API-FUNKTIONEN (HINZUGEFÜGT) ---
 
 /** API-Aufruf zum Laden der Details einer einzelnen Vorlage */
 export const loadTemplateDetails = async (templateId) => {
       // Dieser Endpunkt muss noch in routes.php und PlanController.php erstellt werden
       // Annahme: GET-Anfrage, gibt { success: true, data: { template: {...}, entries: [...] } } zurück
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/${templateId}`);
 };
 
 /** API-Aufruf zum Speichern/Aktualisieren einer Vorlage aus dem Editor */
 export const saveTemplate = async (templateData) => {
       // Dieser Endpunkt muss noch in routes.php und PlanController.php erstellt werden
       // Annahme: POST-Anfrage, sendet { template_id (optional), name, description, entries: [...] }
      return await apiFetch(`${window.APP_CONFIG.baseUrl}/api/planer/templates/save`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(templateData)
      });
 };
