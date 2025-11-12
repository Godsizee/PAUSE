<?php
include_once dirname(__DIR__) . '/partials/header.php';
?>
<div class="page-wrapper admin-dashboard-wrapper">
    <h1 class="main-title">Anwendungs-Einstellungen</h1>
    <div class="dashboard-grid">
        <?php include_once __DIR__ . '/partials/_sidebar.php'; ?>
        <main class="dashboard-content" id="settings-management">
            <div class="dashboard-section active">
                <form id="settings-form" data-mode="update" method="POST" enctype="multipart/form-data">
                    <?php \App\Core\Security::csrfInput(); // CSRF-Token ?>
                    <div class="form-container settings-section">
                        <h3>Allgemein</h3>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="site_title">Seitentitel</label>
                                <p>Der Name der Anwendung, der im Header und im Browser-Tab angezeigt wird.</p>
                            </div>
                            <div class="setting-control">
                                <input type="text"
                                       id="site_title"
                                       name="site_title"
                                       value="<?php echo htmlspecialchars($currentSettings['site_title'] ?? 'PAUSE Portal'); ?>"
                                       required>
                            </div>
                        </div>
                    </div>
                    <div class="form-container settings-section" style="margin-top: 25px;">
                        <h3>Branding &amp; Design</h3>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="site_logo">Logo (Header)</label>
                                <p>Optional. Wird statt dem Seitentitel im Header angezeigt. Empfohlene Höhe: 40px. (PNG, JPG, SVG, GIF)</p>
                            </div>
                            <div class="setting-control file-upload-control">
                                <input type="file" id="site_logo" name="site_logo" accept="image/png,image/jpeg,image/svg+xml,image/gif">
                                <div class="file-preview" id="logo-preview-container">
                                    <?php if (!empty($currentSettings['site_logo_path'])): ?>
                                        <img src="<?php echo htmlspecialchars(\App\Core\Utils::url($currentSettings['site_logo_path'])); ?>?t=<?php echo time(); ?>" alt="Logo Vorschau" class="logo-preview">
                                        <label class="remove-file-label">
                                            <input type="checkbox" name="remove_site_logo" value="1"> Logo entfernen
                                        </label>
                                    <?php else: ?>
                                        <span class="no-file">Kein Logo festgelegt.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="site_favicon">Favicon</label>
                                <p>Optional. Das Icon, das im Browser-Tab angezeigt wird. (ICO, PNG, SVG)</p>
                            </div>
                            <div class="setting-control file-upload-control">
                                <input type="file" id="site_favicon" name="site_favicon" accept="image/x-icon,image/png,image/svg+xml">
                                <div class="file-preview" id="favicon-preview-container">
                                    <?php if (!empty($currentSettings['site_favicon_path'])): ?>
                                        <img src="<?php echo htmlspecialchars(\App\Core\Utils::url($currentSettings['site_favicon_path'])); ?>?t=<?php echo time(); ?>" alt="Favicon Vorschau" class="favicon-preview">
                                        <label class="remove-file-label">
                                            <input type="checkbox" name="remove_site_favicon" value="1"> Favicon entfernen
                                        </label>
                                    <?php else: ?>
                                        <span class="no-file">Kein Favicon festgelegt.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="default_theme">Standard-Theme</label>
                                <p>Das Farbschema, das für Gäste und neue Benutzer standardmäßig geladen wird.</p>
                            </div>
                            <div class="setting-control">
                                <select id="default_theme" name="default_theme" style="max-width: 250px;">
                                    <option value="light" <?php echo ($currentSettings['default_theme'] === 'light') ? 'selected' : ''; ?>>
                                        Hell (Light Mode)
                                    </option>
                                    <option value="dark" <?php echo ($currentSettings['default_theme'] === 'dark') ? 'selected' : ''; ?>>
                                        Dunkel (Dark Mode)
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-container settings-section" style="margin-top: 25px;">
                        <h3>System &amp; Sicherheit</h3>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="maintenance_mode">Wartungsmodus</label>
                                <p>Wenn aktiv, können sich nur noch Admins und Planer anmelden. Alle anderen Benutzer sehen eine Wartungsseite.</p>
                            </div>
                            <div class="setting-control">
                                <label class="toggle-switch">
                                    <input type="checkbox"
                                           id="maintenance_mode"
                                           name="maintenance_mode"
                                           value="1"
                                           <?php echo($currentSettings['maintenance_mode'] ? 'checked' : ''); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <span id="maintenance-status" class="toggle-status">
                                    <?php echo($currentSettings['maintenance_mode'] ? 'Aktiviert' : 'Deaktiviert'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="maintenance_message">Wartungsmeldung</label>
                                <p>Diese Nachricht wird angezeigt, wenn der Wartungsmodus aktiv ist.</p>
                            </div>
                            <div class="setting-control" style="align-items: flex-start;">
                                <textarea id="maintenance_message"
                                          name="maintenance_message"
                                          rows="3"
                                          style="min-height: 80px; width: 100%; max-width: 450px;"
                                          placeholder="Die Anwendung wird gerade gewartet..."><?php echo htmlspecialchars($currentSettings['maintenance_message'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="maintenance_whitelist_ips">IP-Whitelist (Wartungsmodus)</label>
                                <p>Diese IP-Adressen können die Seite auch im Wartungsmodus sehen. Trennen Sie IPs durch ein Komma (,).</p>
                            </div>
                            <div class="setting-control" style="align-items: flex-start;">
                                <textarea id="maintenance_whitelist_ips"
                                          name="maintenance_whitelist_ips"
                                          rows="3"
                                          style="min-height: 80px; width: 100%; max-width: 450px;"
                                          placeholder="127.0.0.1, ::1, 192.168.1.100"><?php echo htmlspecialchars($currentSettings['maintenance_whitelist_ips'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label>Login-Sperre</label>
                                <p>Konfiguriert den Schutz vor Brute-Force-Angriffen beim Login.</p>
                            </div>
                            <div class="setting-control" style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                    <label for="max_login_attempts" style="font-weight: normal; font-size: 0.9rem;">Max. Fehlversuche</label>
                                    <input type="number"
                                           id="max_login_attempts"
                                           name="max_login_attempts"
                                           min="1" max="100"
                                           value="<?php echo htmlspecialchars($currentSettings['max_login_attempts'] ?? '5'); ?>"
                                           required>
                                    <small class="form-hint">Bevor der Account gesperrt wird.</small>
                                </div>
                                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                    <label for="lockout_minutes" style="font-weight: normal; font-size: 0.9rem;">Sperrdauer (Minuten)</label>
                                    <input type="number"
                                           id="lockout_minutes"
                                           name="lockout_minutes"
                                           min="1" max="1440"
                                           value="<?php echo htmlspecialchars($currentSettings['lockout_minutes'] ?? '15'); ?>"
                                           required>
                                    <small class="form-hint">Wie lange der Account gesperrt bleibt.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-container settings-section" style="margin-top: 25px;">
                        <h3>Planer &amp; Module</h3>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label>Standard-Stundenraster</label>
                                <p>Legt die Anzahl der Stunden fest, die im Planer standardmäßig angezeigt werden (z.B. 1-10).</p>
                            </div>
                            <div class="setting-control" style="display: flex; gap: 15px;">
                                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                    <label for="default_start_hour" style="font-weight: normal; font-size: 0.9rem;">Erste Stunde</label>
                                    <input type="number"
                                           id="default_start_hour"
                                           name="default_start_hour"
                                           min="1" max="12"
                                           value="<?php echo htmlspecialchars($currentSettings['default_start_hour'] ?? '1'); ?>"
                                           required>
                                </div>
                                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                    <label for="default_end_hour" style="font-weight: normal; font-size: 0.9rem;">Letzte Stunde</label>
                                    <input type="number"
                                           id="default_end_hour"
                                           name="default_end_hour"
                                           min="1" max="12"
                                           value="<?php echo htmlspecialchars($currentSettings['default_end_hour'] ?? '10'); ?>"
                                           required>
                                </div>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="ical_enabled">iCal Kalender-Feeds</label>
                                <p>Erlaubt Benutzern (Schülern/Lehrern), ihren persönlichen Stundenplan als iCal-Feed zu abonnieren.</p>
                            </div>
                            <div class="setting-control">
                                <label class="toggle-switch">
                                    <input type="checkbox"
                                           id="ical_enabled"
                                           name="ical_enabled"
                                           value="1"
                                           <?php echo($currentSettings['ical_enabled'] ? 'checked' : ''); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <span id="ical-status" class="toggle-status">
                                    <?php echo($currentSettings['ical_enabled'] ? 'Aktiviert' : 'Deaktiviert'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="ical_weeks_future">iCal-Zeitraum (Zukunft)</label>
                                <p>Anzahl der Wochen, die der iCal-Feed in die Zukunft exportieren soll (Standard: 8).</p>
                            </div>
                            <div class="setting-control">
                                <div class="form-group" style="flex-basis: 150px; margin-bottom: 0;">
                                    <input type="number"
                                           id="ical_weeks_future"
                                           name="ical_weeks_future"
                                           min="1" max="52"
                                           value="<?php echo htmlspecialchars($currentSettings['ical_weeks_future'] ?? '8'); ?>"
                                           required>
                                     <small class="form-hint">Wochen (1-52)</small>
                                </div>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="pdf_footer_text">PDF Export Footer-Text</label>
                                <p>Dieser Text erscheint unten links auf jedem PDF-Stundenplan-Export. (z.B. Schulname & Adresse)</p>
                            </div>
                            <div class="setting-control" style="flex-direction: column; align-items: flex-start;">
                                <textarea id="pdf_footer_text"
                                          name="pdf_footer_text"
                                          rows="3"
                                          style="min-height: 80px; width: 100%; max-width: 450px;"
                                          placeholder="z.B. PAUSE Portal - Deine Schule - Schulstraße 1"><?php echo htmlspecialchars($currentSettings['pdf_footer_text'] ?? ''); ?></textarea>
                                <small class="form-hint" style="margin-top: 8px;">Hinweis: Nur Standard-Satzzeichen (ASCII/ISO-8859-1) verwenden. Emojis oder spezielle UTF-8-Zeichen werden im PDF nicht korrekt dargestellt.</small>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <label for="community_board_enabled">Schwarzes Brett (Community)</label>
                                <p>Aktiviert oder deaktiviert das Schwarze Brett für alle Benutzer (Schüler, Lehrer, Planer).</p>
                            </div>
                            <div class="setting-control">
                                <label class="toggle-switch">
                                    <input type="checkbox"
                                           id="community_board_enabled"
                                           name="community_board_enabled"
                                           value="1"
                                           <?php echo($currentSettings['community_board_enabled'] ? 'checked' : ''); ?>>
                                    <span class="slider round"></span>
                                </label>
                                <span id="community-board-status" class="toggle-status">
                                    <?php echo($currentSettings['community_board_enabled'] ? 'Aktiviert' : 'Deaktiviert'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top: 30px; border-top: 1px solid var(--color-border); padding-top: 25px;">
                        <button type="submit" class="btn btn-primary" id="save-settings-btn">
                            Einstellungen speichern
                        </button>
                        <span id="save-settings-spinner" class="loading-spinner small" style="display: none; margin-left: 15px;"></span>
                    </div>
                </form>
                <div class="form-container settings-section" style="margin-top: 30px;">
                    <h3>System-Wartung</h3>
                    <div class="setting-row">
                        <div class="setting-info">
                            <label>Anwendungs-Cache</label>
                            <p>Löscht alle zwischengespeicherten Daten (z.B. Stundenpläne, Einstellungen). Dies kann helfen, Anzeigefehler nach Änderungen zu beheben.</p>
                        </div>
                        <div class="setting-control cache-control-section" style="flex-direction: column; align-items: flex-start;">
                            <input type="hidden" id="cache_csrf_token" value="<?php echo htmlspecialchars(\App\Core\Security::getCsrfToken()); ?>">
                            <button type="button" id="clear-cache-btn" class="btn btn-secondary" style="width: auto; margin-bottom: 0;">
                                Cache jetzt leeren
                            </button>
                            <small id="cache-clear-status" class="form-text" style="margin-top: 10px; display: block;"></small>
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