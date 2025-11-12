<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Benutzerverwaltung</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="user-management">
            <div class="dashboard-section active" id="user-import-section">
                <div class="section-header">
                    <h3>Benutzer-Massenimport (CSV)</h3>
                </div>
                <div class="form-container" style="background-color: var(--color-surface-alt);">
                    <form id="user-import-form" enctype="multipart/form-data" method="POST">
                        <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                        <div class="form-group">
                            <label for="csv_file">CSV-Datei auswählen*</label>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                            <small class="form-hint">
                                Die CSV-Datei muss exakt die Spalten der Vorlage enthalten.
                                <a href="<?php echo htmlspecialchars(\App\Core\Utils::url('admin/csv-template')); ?>" target="_blank" class="template-download-link">
                                    CSV-Vorlage
                                </a>
                            </small>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="user-import-btn">Import starten</button>
                        </div>
                    </form>
                    <div id="import-results-container" style="display: none; margin-top: 20px;">
                        <h4>Importergebnisse:</h4>
                        <pre id="import-results" class="import-results-box"></pre>
                    </div>
                </div>
            </div>
            <div class="dashboard-section active" id="user-list-section">
                <div class="section-header">
                    <h3>Alle Benutzer</h3>
                </div>
                <div>
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="data-table" id="users-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Rolle</th>
                                        <th>Details</th>
                                        <th>Community</th> <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6">Lade Benutzer...</td></tr> </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="form-container">
                        <h4 id="user-form-title">Neuen Benutzer anlegen</h4>
                        <form id="user-form" data-mode="create">
                            <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                            <input type="hidden" name="user_id" id="user_id">
                            <div class="form-grid-col-2">
                                <div class="form-group">
                                    <label for="first_name">Vorname*</label>
                                    <input type="text" name="first_name" id="first_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="last_name">Nachname*</label>
                                    <input type="text" name="last_name" id="last_name" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="username">Benutzername*</label>
                                <input type="text" name="username" id="username" required>
                            </div>
                            <div class="form-group">
                                <label for="email">E-Mail*</label>
                                <input type="email" name="email" id="email" required>
                            </div>
                            <div class="form-group">
                                <label for="password">Passwort</label>
                                <input type="password" name="password" id="password" placeholder="Beim Bearbeiten leer lassen">
                                <small class="form-hint">Mindestens 8 Zeichen. Nur beim Erstellen erforderlich.</small>
                            </div>
                            <div class="form-group">
                                <label for="birth_date">Geburtsdatum</label>
                                <input type="date" name="birth_date" id="birth_date">
                            </div>
                            <hr>
                            <div class="form-group">
                                <label for="role">Rolle*</label>
                                <select name="role" id="role" required>
                                </select>
                            </div>
                            <div id="role-specific-fields">
                                <div class="form-group" id="class-select-container" style="display: none;">
                                    <label for="class_id">Klasse</label>
                                    <select name="class_id" id="class_id">
                                    </select>
                                </div>
                                <div class="form-group" id="teacher-select-container" style="display: none;">
                                    <label for="teacher_id">Lehrerprofil</label>
                                    <select name="teacher_id" id="teacher_id">
                                    </select>
                                </div>
                                <div class="form-group" id="community-ban-container" style="display: none;">
                                    <label class="checkbox-label" for="is_community_banned">
                                        <input type="checkbox" name="is_community_banned" id="is_community_banned" value="1">
                                        Für Schwarzes Brett sperren
                                    </label>
                                    <small class="form-hint">Wenn angehakt, kann dieser Schüler keine Beiträge sehen oder erstellen.</small>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" id="cancel-edit-user" style="display: none;">Abbrechen</button>
                                <button type="submit" class="btn btn-primary">Speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>