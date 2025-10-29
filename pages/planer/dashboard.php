<?php
// pages/planer/dashboard.php
include_once dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-wrapper planer-dashboard-wrapper">
    <!-- Main header section for the planner view -->
    <div class="planer-header">
        <h1 class="main-title">Stundenplan-Verwaltung</h1>

        <!-- Wrapper for all control elements on the right side -->
        <div class="planer-actions-wrapper">

            <!-- Filter controls section -->
            <div class="planer-controls">
                <!-- View mode switcher (Class/Teacher) -->
                <div class="form-group view-switcher">
                    <label for="view-mode-selector">Ansicht:</label>
                    <select id="view-mode-selector">
                        <option value="class" selected>Klassenansicht</option>
                        <option value="teacher">Lehreransicht</option>
                    </select>
                </div>
                 <!-- Class selector dropdown -->
                <div class="form-group" id="class-selector-container">
                    <label for="class-selector">Klasse:</label>
                    <select id="class-selector"></select>
                </div>
                 <!-- Teacher selector dropdown (initially hidden) -->
                <div class="form-group hidden" id="teacher-selector-container">
                    <label for="teacher-selector">Lehrer:</label>
                    <select id="teacher-selector"></select>
                </div>
                 <!-- Year selector -->
                <div class="form-group">
                    <label for="year-selector">Jahr:</label>
                    <select id="year-selector"></select>
                </div>
                <!-- Week selector -->
                <div class="form-group">
                    <label for="week-selector">KW:</label>
                    <select id="week-selector"></select>
                </div>
                <!-- Date selector (for viewing specific day's substitutions) -->
                <div class="form-group">
                    <label for="date-selector">Datum für Vertr.:</label>
                    <input type="date" id="date-selector">
                </div>
            </div> <!-- End planer-controls -->

            <!-- Publish controls section -->
            <div class="publish-controls">
                 <!-- Display area for the current week's publish status -->
                 <div class="publish-status">
                    Status KW <span id="publish-week-label">--</span>:
                    <span id="publish-status-student" class="status-indicator">Schüler: ?</span>
                    <span id="publish-status-teacher" class="status-indicator">Lehrer: ?</span>
                 </div>
                 <!-- Action buttons for publishing/unpublishing -->
                 <div class="publish-actions">
                    <button id="publish-student-btn" class="btn btn-success btn-small">Für Schüler veröffentlichen</button>
                    <button id="publish-teacher-btn" class="btn btn-success btn-small">Für Lehrer veröffentlichen</button>
                    <button id="unpublish-student-btn" class="btn btn-warning btn-small hidden">Schüler zurückziehen</button>
                    <button id="unpublish-teacher-btn" class="btn btn-warning btn-small hidden">Lehrer zurückziehen</button>
                 </div>
            </div> <!-- End publish-controls -->

        </div> <!-- End planer-actions-wrapper -->
    </div> <!-- End planer-header -->

    <!-- NEU: Grid-Layout für Sidebar und Hauptinhalt -->
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; // NEU: Planer-Sidebar einbinden ?>

        <main class="dashboard-content" id="planer-main-content">
            <!-- Container where the timetable grid will be rendered -->
            <div class="timetable-container" id="timetable-container">
                <div class="loading-spinner"></div> <!-- Loading indicator -->
            </div>

            <!-- Massenbearbeitung MOVED HERE -->
            <div class="bulk-actions-controls" style="margin-top: 20px;"> <!-- Added margin-top for spacing -->
                <button id="copy-week-btn" class="btn btn-secondary btn-small">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/></svg>
                    Woche kopieren...
                </button>
                <!-- NEUE Buttons für Vorlagen -->
                <button id="create-template-btn" class="btn btn-secondary btn-small">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2zm0 1h8a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1m1 4h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1 0-1m0 2h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1 0-1m0 2h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1 0-1"/></svg>
                    Vorlage erstellen...
                </button>
                <button id="apply-template-btn" class="btn btn-secondary btn-small">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6.5 0A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0zM5 5.5A1.5 1.5 0 0 0 3.5 7v1A1.5 1.5 0 0 0 5 9.5h6a1.5 1.5 0 0 0 1.5-1.5v-1A1.5 1.5 0 0 0 11 5.5zM3.5 11A1.5 1.5 0 0 0 2 12.5v1A1.5 1.5 0 0 0 3.5 15h9a1.5 1.5 0 0 0 1.5-1.5v-1A1.5 1.5 0 0 0 12.5 11z"/></svg>
                    Vorlage anwenden...
                </button>
                <button id="manage-templates-btn" class="btn btn-secondary btn-small">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 1v3H1V1zM1 0a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1V1a1 1 0 0 0-1-1zm14 12v3H10v-3zm-1-1a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1zM6 8v7H1V8zM1 7a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1zm14-6v7H10V1zm-1-1a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h5a1 1 0 0 0 1-1V1a1 1 0 0 0-1-1z"/></svg>
                    Vorlagen verwalten...
                </button>
                <!-- Platzhalter für zukünftige Aktionen wie "Drucken" -->
            </div>
        </main>
    </div> <!-- Ende .dashboard-grid -->


    <!-- Modal for editing/creating timetable entries -->
     <div id="timetable-modal" class="modal-overlay">
         <div class="modal-box">
             <h2 id="modal-title">Eintrag bearbeiten</h2>
             <form id="timetable-entry-form">
                 <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                 <!-- Hidden fields to store context -->
                 <input type="hidden" id="entry_id" name="entry_id">
                 <input type="hidden" id="block_id" name="block_id">
                 <input type="hidden" id="substitution_id" name="substitution_id">
                 <input type="hidden" id="modal_day_of_week" name="day_of_week">
                 <input type="hidden" id="modal_period_number" name="period_number">
                 <input type="hidden" id="original_subject_id" name="original_subject_id">
                 <input type="hidden" id="modal_editing_template" name="editing_template" value="false"> <!-- NEU: Flag für Template-Editor -->


                 <!-- Tabs to switch between regular entry and substitution -->
                 <div class="modal-tabs">
                     <button type="button" class="tab-button active" data-mode="regular">Reguläre Stunde</button>
                     <button type="button" class="tab-button" data-mode="substitution">Vertretung/Änderung</button>
                 </div>

                 <!-- Fields for regular timetable entry -->
                 <div id="regular-fields" class="modal-tab-content active">
                     <!-- Klassen-Auswahl für Template-Editor (wird nur dort angezeigt) -->
                     <div class="form-group" id="template-class-select-container" style="display: none;">
                         <!-- KORRIGIERTES LABEL UND HILFETEXT -->
                         <label for="template_class_id">Standard-Klasse (für Lehrer-Vorlagen)</label>
                         <select id="template_class_id" name="class_id" class="conflict-check"></select>
                         <small class="form-hint">Nötig, wenn Vorlage auf Lehrer angewendet wird. Wird ignoriert, wenn Vorlage auf Klasse angewendet wird.</small>
                     </div>
                    <div class="form-group">
                        <label for="subject_id">Fach</label>
                        <select id="subject_id" name="subject_id" class="conflict-check" required></select>
                    </div>
                    <div class="form-group">
                        <label for="teacher_id">Lehrer</label>
                        <select id="teacher_id" name="teacher_id" class="conflict-check" required></select>
                    </div>
                    <div class="form-group">
                        <label for="room_id">Raum</label>
                        <select id="room_id" name="room_id" class="conflict-check" required></select>
                    </div>
                    <div class="form-group">
                        <label for="regular_comment">Kommentar (optional)</label>
                        <input type="text" id="regular_comment" name="comment" placeholder="z.B. Klausur, Besonderheit">
                    </div>
                 </div>

                 <!-- Fields for substitution entry -->
                 <div id="substitution-fields" class="modal-tab-content">
                     <!-- Substitution Fields bleiben wie gehabt -->
                      <div class="form-group">
                        <label for="substitution_type">Art der Änderung</label>
                        <select id="substitution_type" name="substitution_type">
                            <option value="Vertretung">Vertretung</option>
                            <option value="Raumänderung">Raumänderung</option>
                            <option value="Entfall">Entfall</option>
                            <option value="Sonderevent">Sonderevent</option>
                        </select>
                    </div>
                    <!-- Dynamically shown fields based on substitution type -->
                    <div id="substitution-details">
                        <div class="form-group sub-field" data-types='["Vertretung", "Sonderevent"]'>
                            <label for="new_subject_id">Neues Fach (optional)</label>
                            <select id="new_subject_id" name="new_subject_id"></select>
                        </div>
                        <div class="form-group sub-field" data-types='["Vertretung"]'>
                            <label for="new_teacher_id">Neuer Lehrer</label>
                            <select id="new_teacher_id" name="new_teacher_id"></select>
                        </div>
                        <div class="form-group sub-field" data-types='["Vertretung", "Raumänderung", "Sonderevent"]'>
                            <label for="new_room_id">Neuer Raum</label>
                            <select id="new_room_id" name="new_room_id"></select>
                        </div>
                         <div class="form-group sub-field" data-types='["Vertretung", "Sonderevent", "Entfall", "Raumänderung"]'>
                            <label for="substitution_comment">Kommentar</label>
                            <input type="text" id="substitution_comment" name="comment" placeholder="z.B. Aula-Veranstaltung, Grund für Entfall">
                        </div>
                    </div>
                 </div>

                 <!-- Konflikt-Warnungs-Box (wird im Template-Editor deaktiviert) -->
                 <div class="modal-conflict-warning" id="modal-conflict-warning" style="display: none;">
                     <!-- Konfliktmeldungen werden hier per JS eingefügt -->
                 </div>


                 <!-- Modal action buttons -->
                 <div class="modal-actions">
                     <button type="button" class="btn btn-danger" id="delete-entry-btn" style="display: none;">Löschen</button>
                     <button type="button" class="btn btn-secondary" id="modal-cancel-btn">Abbrechen</button>
                     <button type="submit" class="btn btn-primary" id="modal-save-btn">Speichern</button>
                 </div>
             </form>
         </div>
     </div> <!-- End timetable-modal -->


    <!-- Modal für Wochenkopie -->
    <div id="copy-week-modal" class="modal-overlay">
        <!-- Inhalt bleibt unverändert -->
         <div class="modal-box">
             <h2 id="copy-week-modal-title">Stundenplan kopieren</h2>
             <form id="copy-week-form">
                 <p>Kopiere den Plan von der aktuell ausgewählten Woche nach:</p>

                 <div class="copy-week-form">
                     <div class="form-group copy-from">
                         <label>Von (Quelle):</label>
                         <input type="text" id="copy-source-display" readonly disabled>
                     </div>
                     <div class="arrow-separator">&rarr;</div>
                     <div class="form-group">
                         <label for="copy-target-year">Ziel-Jahr*</label>
                         <select id="copy-target-year" required></select>
                     </div>
                      <div class="form-group">
                         <label for="copy-target-week">Ziel-KW*</label>
                         <select id="copy-target-week" required></select>
                     </div>
                 </div>

                 <div class="modal-conflict-warning" id="copy-week-warning">
                     <strong>Achtung:</strong> Alle vorhandenen Stundenplan-Einträge für die ausgewählte Klasse/Lehrer in der Zielwoche werden überschrieben. Vertretungen werden nicht kopiert.
                 </div>

                 <div class="modal-actions">
                     <button type="button" class="btn btn-secondary" id="copy-week-cancel-btn">Abbrechen</button>
                     <button type="submit" class="btn btn-primary" id="copy-week-confirm-btn">Kopieren & Überschreiben</button>
                 </div>
             </form>
         </div>
     </div>
     <!-- ENDE Modal für Wochenkopie -->

    <!-- Modal zum Erstellen/Verwalten von Vorlagen -->
    <div id="manage-templates-modal" class="modal-overlay">
        <div class="modal-box"> <!-- Style wurde in planer.css angepasst (breiter) -->
            <h2 id="manage-templates-modal-title">Vorlagen verwalten</h2>

            <!-- Container für die Ansichten: Liste vs. Editor -->
            <div id="manage-templates-view-container">

                <!-- Ansicht 1: Liste der Vorlagen + Erstellen-Optionen -->
                <div id="template-list-view" class="manage-templates-view active">
                    <form id="create-template-form">
                         <h4>Neue Vorlage aus aktueller Woche erstellen</h4>
                         <div style="display: flex; gap: 15px; align-items: flex-end; margin-bottom: 15px;">
                             <div class="form-group" style="flex-grow: 1;">
                                 <label for="template-name">Vorlagenname*</label>
                                 <input type="text" id="template-name" name="template-name" required placeholder="z.B. Standardwoche A, Projektwoche">
                             </div>
                             <div class="form-group" style="flex-grow: 2;">
                                 <label for="template-description">Beschreibung (optional)</label>
                                 <input type="text" id="template-description" name="template-description" placeholder="Kurze Info, was diese Vorlage enthält">
                                 <!-- Ersetzt Textarea durch Input für Einzeiligkeit -->
                             </div>
                             <div class="form-actions" style="margin-top: 0; margin-bottom: 0;">
                                 <button type="submit" class="btn btn-success">Aus Woche speichern</button>
                             </div>
                         </div>
                    </form>

                    <hr style="margin: 25px 0;">

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                         <h4>Bestehende Vorlagen</h4>
                         <button type="button" id="create-empty-template-btn" class="btn btn-primary btn-small">
                             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zM2 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/></svg>
                             Neue leere Vorlage
                         </button>
                    </div>

                    <div id="templates-list-container" style="max-height: 300px; overflow-y: auto;">
                         <!-- Liste wird per JS gefüllt -->
                         <p>Lade Vorlagen...</p>
                    </div>
                </div>

                <!-- Ansicht 2: Editor für eine neue/bestehende Vorlage (Initial hidden) -->
                <div id="template-editor-view" class="manage-templates-view" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                         <h4 id="template-editor-title">Leere Vorlage erstellen</h4>
                         <div>
                             <button type="button" id="back-to-template-list-btn" class="btn btn-secondary btn-small">Zurück zur Liste</button>
                             <button type="button" id="save-template-editor-btn" class="btn btn-success btn-small">Vorlage speichern</button>
                         </div>
                    </div>
                    <!-- Vorlagen-Details (Name, Beschreibung) -->
                    <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                         <div class="form-group" style="flex-grow: 1;">
                             <label for="template-editor-name">Vorlagenname*</label>
                             <input type="text" id="template-editor-name" required>
                         </div>
                         <div class="form-group" style="flex-grow: 2;">
                             <label for="template-editor-description">Beschreibung</label>
                             <input type="text" id="template-editor-description">
                         </div>
                    </div>

                    <p style="color: var(--color-text-muted); font-size: 0.9em; margin-top: 10px;">
                         Klicken Sie auf eine Zelle, um einen Eintrag hinzuzufügen/zu bearbeiten. Die Klasse wird beim Anwenden der Vorlage auf eine Klasse automatisch angepasst.
                         Wenn Sie die Vorlage auf einen Lehrer anwenden, wird die hier gewählte Klasse verwendet (relevant für Vertretungen etc.).
                    </p>
                    <!-- Container für das simplifizierte Grid -->
                    <div id="template-editor-grid-container" style="margin-top: 15px; border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden;">
                         <!-- Grid wird hier per JS eingefügt -->
                         <div class="loading-spinner"></div>
                    </div>
                </div>

            </div> <!-- Ende View Container -->

            <div class="modal-actions" style="margin-top: 25px;">
                <button type="button" class="btn btn-secondary" id="manage-templates-close-btn">Schließen</button>
            </div>
        </div>
    </div>
    <!-- ENDE Modal zum Erstellen/Verwalten -->

    <!-- Modal zum Anwenden einer Vorlage -->
    <div id="apply-template-modal" class="modal-overlay">
        <!-- Inhalt bleibt unverändert -->
        <div class="modal-box">
            <h2 id="apply-template-modal-title">Vorlage anwenden</h2>
            <form id="apply-template-form">
                 <p>Wähle eine Vorlage aus, die auf die aktuell ausgewählte Woche angewendet werden soll.</p>
                 <div class="form-group">
                     <label for="apply-template-select">Vorlage auswählen*</label>
                     <select id="apply-template-select" name="templateId" required>
                         <option value="">Lade Vorlagen...</option>
                         <!-- Optionen werden per JS gefüllt -->
                     </select>
                 </div>
                 <div class="modal-conflict-warning">
                     <strong>Achtung:</strong> Alle vorhandenen Stundenplan-Einträge für die ausgewählte Klasse/Lehrer in der aktuellen Woche werden durch die Vorlage überschrieben. Vertretungen bleiben unberührt.
                 </div>
                 <div class="modal-actions">
                     <button type="button" class="btn btn-secondary" id="apply-template-cancel-btn">Abbrechen</button>
                     <button type="submit" class="btn btn-primary" id="apply-template-confirm-btn">Vorlage anwenden & Überschreiben</button>
                 </div>
            </form>
        </div>
    </div>
    <!-- ENDE Modal zum Anwenden -->

</div> <!-- End page-wrapper -->

<?php
// Include the footer partial
include_once dirname(__DIR__) . '/partials/footer.php';
?>
