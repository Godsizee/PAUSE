<?php
// pages/planer/absences.php
// NEU: Diese Datei erstellt die Seite für die Abwesenheitsverwaltung.
include_once dirname(__DIR__) . '/partials/header.php';

// $availableTeachers und $absenceTypes werden vom AbsenceController->index() übergeben
?>

<div class="page-wrapper planer-dashboard-wrapper">
    <h1 class="main-title">Lehrer-Abwesenheiten</h1>

    <!-- Grid-Layout für Sidebar und Hauptinhalt -->
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; // Planer-Sidebar einbinden ?>

        <main class="dashboard-content" id="absence-management">
            
            <div class="dashboard-section active">
                <div class="dashboard-widget-grid" id="absence-grid">

                    <!-- Widget zum Eintragen neuer Abwesenheiten -->
                    <div class="dashboard-widget" id="widget-add-absence">
                        <!-- KORREKTUR: ID hinzugefügt, die vom JS erwartet wird -->
                        <h3 id="absence-form-title">Neue Abwesenheit eintragen</h3>
                        <div class="form-container" style="background: transparent; border: none; padding: 0; box-shadow: none; margin: 0;">
                            <form id="absence-form" data-mode="create">
                                <?php \App\Core\Security::csrfInput(); // CSRF-Token ?>
                                
                                <div class="form-group">
                                    <label for="absence-teacher-id">Lehrer*</label>
                                    <select name="teacher_id" id="absence-teacher-id" required>
                                        <option value="">-- Lehrer wählen --</option>
                                        <?php foreach ($availableTeachers as $teacher): ?>
                                            <?php // SGL herausfiltern ?>
                                            <?php if ($teacher['teacher_shortcut'] !== 'SGL'): ?>
                                                <option value="<?php echo htmlspecialchars($teacher['teacher_id']); ?>">
                                                    <?php echo htmlspecialchars($teacher['last_name'] . ', ' . $teacher['first_name'] . ' (' . $teacher['teacher_shortcut'] . ')'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-grid-col-2">
                                    <div class="form-group">
                                        <label for="absence-start-date">Von Datum*</label>
                                        <input type="date" name="start_date" id="absence-start-date" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="absence-end-date">Bis Datum*</label>
                                        <input type="date" name="end_date" id="absence-end-date" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="absence-reason">Grund*</label>
                                    <select name="reason" id="absence-reason" required>
                                        <option value="">-- Grund wählen --</option>
                                        <?php foreach ($absenceTypes as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>">
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="absence-comment">Kommentar (optional)</label>
                                    <input type="text" name="comment" id="absence-comment" placeholder="z.B. Details zur Fortbildung...">
                                </div>
                                
                                <!-- Verstecktes Feld für die ID (wird beim Bearbeiten befüllt) -->
                                <input type="hidden" name="absence_id" id="absence-id">

                                <div class="form-actions" style="margin-top: 20px; display: flex; gap: 10px;">
                                    <button type="submit" class="btn btn-primary" id="absence-save-btn">Speichern</button>
                                    <button type="button" class="btn btn-secondary" id="absence-cancel-edit-btn" style="display: none;">Abbrechen</button>
                                    <button type="button" class="btn btn-danger" id="absence-delete-btn" style="display: none; margin-left: auto;">Löschen</button>
                                    <span id="absence-save-spinner" class="loading-spinner small" style="display: none; margin-left: 10px;"></span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Widget für die Kalenderansicht (FullCalendar) -->
                    <div class="dashboard-widget" id="widget-absence-calendar" style="grid-column: 1 / -1;">
                        <h3>Abwesenheits-Kalender</h3>
                        <div id="absence-calendar" style="min-height: 600px; margin-top: 20px;">
                            <div class="loading-spinner"></div>
                            <!-- FullCalendar wird hier per JS initialisiert -->
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div> <!-- Ende .dashboard-grid -->

</div> <!-- End page-wrapper -->

<?php
// Include the footer partial
include_once dirname(__DIR__) . '/partials/footer.php';
?>

