<?php
// pages/admin/announcements.php
include_once dirname(__DIR__) . '/partials/header.php';

// Data ($allAnnouncements, $availableClasses, $userRole) is passed from AdminAnnouncementController->index()
?>

<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Ankündigungsverwaltung</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="announcements-management">
            <div class="dashboard-section active">
                <div class="form-container">
                    <h4 id="announcement-form-title">Neue Ankündigung erstellen</h4>
                    <!-- KORRIGIERT: method="POST" hinzugefügt -->
                    <form id="announcement-form" data-mode="create" method="POST" enctype="multipart/form-data">
                        <?php \App\Core\Security::csrfInput(); // Add CSRF input field ?>
                        <input type="hidden" name="announcement_id" id="announcement_id">

                        <div class="form-group">
                            <label for="title">Titel*</label>
                            <input type="text" name="title" id="title" required>
                        </div>

                        <div class="form-group">
                            <label for="content">Inhalt*</label>
                            <textarea name="content" id="content" rows="5" required></textarea>
                            <small class="form-hint">Sie können <a href="https://www.markdownguide.org/basic-syntax/" target="_blank">Markdown</a> für die Formatierung verwenden (z.B. **fett**, *kursiv*, Listen).</small>
                        </div>

                        <?php if (in_array($userRole, ['admin', 'planer'])): ?>
                            <fieldset class="target-group-fieldset">
                                <legend>Zielgruppe auswählen*</legend>

                                <div class="form-group" id="target-class-container">
                                    <label for="target_class_id">Klassenspezifisch (nur Schüler)</label>
                                    <select name="target_class_id" id="target_class_id">
                                        <option value="">-- Klasse wählen --</option>
                                        <?php foreach ($availableClasses as $class): ?>
                                            <option value="<?php echo htmlspecialchars($class['class_id']); ?>">
                                                <?php echo htmlspecialchars($class['class_id'] . ' - ' . $class['class_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-hint">ODER eine der folgenden Optionen wählen:</small>
                                </div>


                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="target_global" id="target_global" value="1">
                                        Globale Ankündigung (Alle Nutzer)
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="target_teacher" id="target_teacher" value="1">
                                        Nur für Lehrer
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="target_planer" id="target_planer" value="1">
                                        Nur für Planer
                                    </label>
                                </div>
                                <small class="form-hint error-hint" id="target-error" style="display: none; color: var(--color-danger); font-weight: bold;">Bitte eine Klasse ODER genau eine Checkbox auswählen.</small>

                            </fieldset>

                            <div class="form-group">
                                <label for="attachment">Anhang (optional, max 5MB)</label>
                                <input type="file" name="attachment" id="attachment">
                                <small class="form-hint">Erlaubte Typen: JPG, PNG, PDF, DOC, DOCX</small>
                                <div id="current-attachment-info" style="display: none; margin-top: 5px;">
                                    Aktueller Anhang: <a href="#" id="current-attachment-link" target="_blank"></a>
                                    <label style="margin-left: 10px;">
                                        <input type="checkbox" name="remove_attachment" id="remove_attachment" value="1"> Anhang entfernen
                                    </label>
                                </div>
                            </div>

                        <?php elseif ($userRole === 'lehrer'): ?>
                            <input type="hidden" name="target_role_fixed" value="schueler">
                            <div class="form-group">
                                <label for="target_class_id">Klasse*</label>
                                <select name="target_class_id" id="target_class_id" required>
                                    <option value="">Bitte wählen...</option>
                                    <?php foreach ($availableClasses as $class): ?>
                                        <option value="<?php echo htmlspecialchars($class['class_id']); ?>">
                                            <?php echo htmlspecialchars($class['class_id'] . ' - ' . $class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Sie können nur Ankündigungen für Schüler einer Klasse erstellen.</small>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancel-edit-announcement" style="display: none;">Abbrechen</button>
                            <button type="submit" class="btn btn-primary">Speichern</button>
                        </div>
                    </form>
                </div>

                <div class="table-container announcements-list">
                    <h3>Bestehende Ankündigungen</h3>
                     <div id="announcements-table-container">
                         <?php if (empty($allAnnouncements)): ?>
                             <p>Keine Ankündigungen vorhanden.</p>
                         <?php else: ?>
                             <table class="data-table" id="announcements-table">
                                 <thead>
                                     <tr>
                                         <th>Titel</th>
                                         <th>Autor</th>
                                         <th>Zielgruppe</th>
                                         <th>Datum</th>
                                         <th>Anhang</th>
                                         <th>Aktionen</th>
                                     </tr>
                                 </thead>
                                 <tbody>
                                     <?php foreach ($allAnnouncements as $ann):
                                         $targetDisplay = '';
                                         if ($ann['is_global']) { $targetDisplay = 'Global'; }
                                         elseif ($ann['class_id']) { $targetDisplay = 'Klasse: ' . htmlspecialchars($ann['target_class_name'] ?? $ann['class_id']); }
                                         else { $targetDisplay = 'Unbekannt'; } // Fallback, sollte nicht vorkommen mit neuer Logik
                                     ?>
                                     <tr data-id="<?php echo $ann['announcement_id']; ?>"
                                         data-title="<?php echo htmlspecialchars($ann['title']); ?>"
                                         data-content="<?php echo htmlspecialchars($ann['content']); ?>"
                                         data-is-global="<?php echo $ann['is_global']; ?>"
                                         data-class-id="<?php echo htmlspecialchars($ann['class_id'] ?? ''); ?>"
                                         data-file-path="<?php echo htmlspecialchars($ann['file_path'] ?? ''); ?>"
                                         data-user-id="<?php echo $ann['user_id']; ?>">
                                         <td><?php echo htmlspecialchars($ann['title']); ?></td>
                                         <td><?php echo htmlspecialchars($ann['author_name'] ?? 'Unbekannt'); ?></td>
                                         <td><?php echo $targetDisplay; ?></td>
                                         <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($ann['created_at']))); ?></td>
                                          <td>
                                              <?php if (!empty($ann['file_url'])): ?>
                                                  <a href="<?php echo htmlspecialchars($ann['file_url']); ?>" target="_blank" title="<?php echo htmlspecialchars(basename($ann['file_path'] ?? 'Anhang')); ?>">
                                                      📎 Anhang
                                                  </a>
                                              <?php else: ?>
                                                  -
                                              <?php endif; ?>
                                          </td>
                                         <td class="actions">
                                             <?php
                                               // Bestimmt, ob der aktuelle Benutzer diesen Eintrag ändern/löschen darf
                                               $canModify = in_array($userRole, ['admin', 'planer']) || ($userRole === 'lehrer' && $ann['user_id'] == $_SESSION['user_id']);
                                             ?>
                                             <?php if ($canModify): ?>
                                                 <!-- <button class="btn btn-warning btn-small edit-announcement">Bearbeiten</button> -->
                                                 <button class="btn btn-danger btn-small delete-announcement">Löschen</button>
                                             <?php endif; ?>
                                         </td>
                                     </tr>
                                     <?php endforeach; ?>
                                 </tbody>
                             </table>
                         <?php endif; ?>
                     </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
include_once dirname(__DIR__) . '/partials/footer.php';
?>
