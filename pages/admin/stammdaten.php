<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Stammdatenverwaltung</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="stammdaten-management">
            <nav class="tab-navigation">
                <button class="tab-button active" data-target="subjects-section">Fächer</button>
                <button class="tab-button" data-target="rooms-section">Räume</button>
                <button class="tab-button" data-target="teachers-section">Lehrer</button>
                <button class="tab-button" data-target="classes-section">Klassen</button>
            </nav>
            <div class="tab-content">
                <div class="dashboard-section active" id="subjects-section">
                    <div class="form-container" id="subject-form-container">
                        <h4>Fach anlegen/bearbeiten</h4>
                        <form id="subject-form" data-mode="create">
                             <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                            <input type="hidden" name="subject_id" id="subject_id">
                            <div class="form-group">
                                <label for="subject_name">Fachname*</label>
                                <input type="text" name="subject_name" id="subject_name" required>
                            </div>
                            <div class="form-group">
                                <label for="subject_shortcut">Kürzel*</label>
                                <input type="text" name="subject_shortcut" id="subject_shortcut" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                                <button type="button" class="btn btn-secondary" id="cancel-edit-subject" style="display: none;">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                    <div class="table-container">
                        <h3>Bestandsdaten Fächer</h3>
                        <div class="table-responsive">
                            <table class="data-table" id="subjects-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Fachname</th>
                                        <th>Kürzel</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="4">Lade Fächer...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="dashboard-section" id="rooms-section">
                     <div class="form-container" id="room-form-container">
                        <h4>Raum anlegen/bearbeiten</h4>
                        <form id="room-form" data-mode="create">
                             <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                            <input type="hidden" name="room_id" id="room_id">
                            <div class="form-group">
                                <label for="room_name">Raumname*</label>
                                <input type="text" name="room_name" id="room_name" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                                <button type="button" class="btn btn-secondary" id="cancel-edit-room" style="display: none;">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                    <div class="table-container">
                        <h3>Bestandsdaten Räume</h3>
                        <div class="table-responsive">
                            <table class="data-table" id="rooms-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Raumname</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="3">Lade Räume...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="dashboard-section" id="teachers-section">
                    <div class="form-container" id="teacher-form-container">
                        <h4>Lehrer anlegen/bearbeiten</h4>
                        <form id="teacher-form" data-mode="create">
                             <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                            <input type="hidden" name="teacher_id" id="teacher_id">
                             <div class="form-group">
                                <label for="teacher_shortcut">Kürzel*</label>
                                <input type="text" name="teacher_shortcut" id="teacher_shortcut" required>
                            </div>
                            <div class="form-group">
                                <label for="first_name">Vorname*</label>
                                <input type="text" name="first_name" id="first_name" required>
                            </div>
                            <div class="form-group">
                                <label for="last_name">Nachname*</label>
                                <input type="text" name="last_name" id="last_name" required>
                            </div>
                            <div class="form-group">
                                <label for="email">E-Mail</label>
                                <input type="email" name="email" id="email">
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                                <button type="button" class="btn btn-secondary" id="cancel-edit-teacher" style="display: none;">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                    <div class="table-container">
                        <h3>Bestandsdaten Lehrer</h3>
                        <div class="table-responsive">
                            <table class="data-table" id="teachers-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Kürzel</th>
                                        <th>Vorname</th>
                                        <th>Nachname</th>
                                        <th>E-Mail</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6">Lade Lehrer...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="dashboard-section" id="classes-section">
                    <div class="form-container" id="class-form-container">
                        <h4>Klasse anlegen/bearbeiten</h4>
                        <form id="class-form" data-mode="create">
                             <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                             <!-- Hidden input for class_id when updating -->
                             <input type="hidden" name="class_id" id="class_id_hidden">
                            <div class="form-group">
                                <label for="class_id_input">Klassennummer (ID)*</label>
                                <input type="number" name="class_id_input" id="class_id_input" required> <!-- Name changed to avoid conflict -->
                                <small class="form-hint">Diese ID ist eindeutig und kann nach dem Erstellen nicht mehr geändert werden.</small>
                            </div>
                            <div class="form-group">
                                <label for="class_name">Klassen-Akronym*</label>
                                <input type="text" name="class_name" id="class_name" required>
                            </div>
                            <div class="form-group">
                                <label for="class_teacher_id">Klassenlehrer</label>
                                <select name="class_teacher_id" id="class_teacher_id">
                                    <option value="">Kein Klassenlehrer</option>
                                    </select>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Speichern</button>
                                <button type="button" class="btn btn-secondary" id="cancel-edit-class" style="display: none;">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                    <div class="table-container">
                        <h3>Bestandsdaten Klassen</h3>
                        <div class="table-responsive">
                            <table class="data-table" id="classes-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Klassen-Akronym</th>
                                        <th>Klassenlehrer</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="4">Lade Klassen...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>