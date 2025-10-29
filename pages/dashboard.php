<?php
// pages/dashboard.php
include_once __DIR__ . '/partials/header.php';
// $today, $dayOfWeekName, $dateFormatted, $icalSubscriptionUrl werden vom Controller übergeben
// $settings wird automatisch vom header.php geladen
$role = $_SESSION['user_role'] ?? 'guest'; // Rolle holen
?>

<div class="page-wrapper dashboard-wrapper">
    <h1 class="main-title">Mein Dashboard</h1>

    <!-- Tab-Navigation (Schuppen) -->
    <nav class="tab-navigation">
        <button class="tab-button active" data-target="section-my-day">🗓️ Mein Tag</button>
        <button class="tab-button" data-target="section-weekly-plan">📅 Wochenplan</button>
        <button class="tab-button" data-target="section-announcements">📢 Ankündigungen</button>
        
        <?php // NEU: Prüfe, ob das Community Board global aktiviert ist ?>
        <?php if ($role === 'schueler' && $settings['community_board_enabled']): ?>
            <button class="tab-button" data-target="section-community-board">📰 Schwarzes Brett</button>
            <button class="tab-button" data-target="section-my-posts">✍️ Deine Beiträge</button>
        <?php endif; ?>

        <?php if ($role === 'schueler' && !$settings['community_board_enabled']): // Nur wenn Schüler, aber Board deaktiviert ?>
             <!-- <button class="tab-button" data-target="section-booking">🧑‍🏫 Sprechstunde buchen</button> -->
             <!-- Platzhalter, falls Sprechstunde (ohne Community) später kommt -->
        <?php endif; ?>

        <?php if ($role === 'lehrer'): ?>
            <button class="tab-button" data-target="section-attendance">✅ Anwesenheit</button>
            <button class="tab-button" data-target="section-events">📚 Aufgaben/Klausuren</button>
            <button class="tab-button" data-target="section-office-hours">🗣️ Sprechzeiten</button>
            <button class="tab-button" data-target="section-colleague-search">🧑‍🤝‍🧑 Kollegensuche</button>
            <?php // Lehrer können Community Board sehen (falls aktiviert), aber nicht "Deine Beiträge" ?>
            <?php if ($settings['community_board_enabled']): ?>
                <button class="tab-button" data-target="section-community-board">📰 Schwarzes Brett</button>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <!-- Tab-Inhalt -->
    <div class="tab-content">

        <!-- Tab 1: Mein Tag (Standard) -->
        <div class="dashboard-section active" id="section-my-day">
            <h2 class="section-title-hidden">Mein Tag <small>(<?php echo $dayOfWeekName . ', ' . $dateFormatted; ?>)</small></h2>
            <div id="today-schedule-container" class="today-schedule-container">
                <div class="loading-spinner small"></div>
            </div>
        </div>

        <!-- Tab 2: Wochenplan -->
        <div class="dashboard-section" id="section-weekly-plan">
            <section class="weekly-timetable-section" id="weekly-timetable-section-printable">
                <div class="dashboard-header">
                    <h2 class="section-title-hidden">Mein Wochenplan</h2>
                    <div class="plan-controls">
                        <div class="form-group">
                            <label for="year-selector">Jahr:</label>
                            <select id="year-selector"></select>
                        </div>
                        <div class="form-group">
                            <label for="week-selector">KW:</label>
                            <select id="week-selector"></select>
                        </div>
                        <div class="print-export-actions form-group">
                            <button id="export-pdf-btn" class="btn btn-primary" title="Als PDF exportieren">
                                PDF Export
                            </button>
                        </div>
                    </div>
                </div>

                <div id="plan-header-info" class="plan-header-info"></div>
                <div class="timetable-container" id="timetable-container">
                    <div class="loading-spinner"></div>
                </div>
            </section>
            
            <?php if (isset($icalSubscriptionUrl)): ?>
            <div class="ical-subscription-box" style="margin-top: 25px;">
                <label for="ical-url">Dein persönlicher Kalender-Feed:</label>
                <div class="input-group">
                    <input type="text" id="ical-url" value="<?php echo htmlspecialchars($icalSubscriptionUrl); ?>" readonly>
                    <button id="copy-ical-url" class="btn btn-secondary btn-small" title="Link kopieren">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M13 0H6a2 2 0 0 0-2 2 2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2 2 2 0 0 0 2-2V2a2 2 0 0 0-2-2Zm0 13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v9ZM2 2a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v1H2V2Z"/>
                        </svg>
                    </button>
                </div>
                <small class="form-hint">Füge diese URL zu deiner Kalender-App hinzu (z.B. Google Kalender, Outlook, Apple Kalender), um deinen Stundenplan zu abonnieren.</small>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab 3: Ankündigungen -->
        <div class="dashboard-section" id="section-announcements">
            <div id="announcements-list" class="sidebar-widget-content">
                <div class="loading-spinner"></div>
            </div>
        </div>

        <!-- Schüler-Tabs (Bedingt) -->
        <?php if ($role === 'schueler' && $settings['community_board_enabled']): ?>
            <!-- Tab 4: Schwarzes Brett -->
            <div class="dashboard-section" id="section-community-board">
                <h4>Digitales Schwarzes Brett</h4>
                <p class="form-hint" style="margin-bottom: 20px;">Informeller Feed für Fundsachen, AGs, Nachhilfe, etc. Beiträge von Schülern werden vor der Veröffentlichung geprüft.</p>

                <form id="community-post-form" class="form-container" style="background-color: var(--color-surface-alt); padding: 15px; margin-bottom: 25px;">
                    <?php \App\Core\Security::csrfInput(); ?>
                    <h5>Neuen Beitrag erstellen</h5>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label for="post-title">Titel*</label>
                        <input type="text" id="post-title" name="title" required placeholder="z.B. Nachhilfe in Mathe gesucht">
                    </div>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="post-content">Inhalt*</label>
                        <textarea id="post-content" name="content" rows="4" required placeholder="Beschreibe dein Anliegen..."></textarea>
                        <small class="form-hint">Sie können <a href="https://www.markdownguide.org/basic-syntax/" target="_blank">Markdown</a> für die Formatierung verwenden.</small>
                    </div>
                    <div class="form-actions" style="margin-top: 0; justify-content: flex-end;">
                        <button type="submit" class="btn btn-primary btn-small" id="create-post-btn" style="width: auto;">Beitrag einreichen</button>
                        <span id="post-create-spinner" class="loading-spinner small" style="display: none; margin-left: 10px;"></span>
                    </div>
                </form>

                <div id="community-posts-list" class="posts-list-container" style="max-height: 60vh; overflow-y: auto;">
                    <div class="loading-spinner"></div>
                </div>
            </div>

            <!-- Tab 5: Deine Beiträge -->
            <div class="dashboard-section" id="section-my-posts">
                 <h4>Deine Beiträge</h4>
                 <p class="form-hint" style="margin-bottom: 20px;">Hier siehst du den Status deiner eingereichten Beiträge und kannst sie bearbeiten. Bearbeitete Beiträge werden erneut zur Moderation vorgelegt.</p>
                 
                 <div id="my-posts-list" class="posts-list-container my-posts-container" style="max-height: 60vh; overflow-y: auto;">
                    <div class="loading-spinner"></div>
                 </div>
            </div>

            <!-- Tab X: Sprechstunde Buchen -->
            <!-- <div class="dashboard-section" id="section-booking"> ... (Code für Sprechstunden) ... </div> -->
        <?php endif; ?>


        <!-- Lehrer-Tabs -->
        <?php if ($role === 'lehrer'): ?>
            <div id="teacher-cockpit" style="display: contents;">
                <!-- Tab 6: Anwesenheit -->
                <div class="dashboard-section" id="section-attendance">
                    <div class="cockpit-feature" id="attendance-feature">
                        <h4>Digitale Anwesenheit</h4>
                        <div id="attendance-current-lesson">
                            <div class="loading-spinner small"></div>
                        </div>
                        <div class="attendance-list-container" id="attendance-list-container" style="display: none;">
                            <div class="attendance-list-header">
                                <span>Schüler/in</span>
                                <span>Status</span>
                            </div>
                            <ul id="attendance-student-list" class="attendance-student-list"></ul>
                            <div class="form-actions" style="margin-top: 15px;">
                                <button class="btn btn-primary" id="save-attendance-btn" style="width: 100%;">
                                    Anwesenheit speichern
                                </button>
                                <span id="attendance-save-spinner" class="loading-spinner small" style="display: none; margin-left: 10px;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 7: Aufgaben/Klausuren -->
                <div class="dashboard-section" id="section-events">
                    <div class="cockpit-feature" id="academic-events-feature">
                        <h4>Aufgaben & Klausuren verwalten</h4>
                        <form id="academic-event-form" class="form-container" style="background-color: var(--color-surface-alt); padding: 15px; margin-bottom: 20px;">
                            <?php \App\Core\Security::csrfInput(); ?>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="event-type">Typ*</label>
                                <select name="event_type" id="event-type" required>
                                    <option value="aufgabe">Aufgabe</option>
                                    <option value="klausur">Klausur / Test</option>
                                    <option value="info">Info</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="event-class-id">Klasse*</label>
                                <select name="class_id" id="event-class-id" required>
                                    <option value="">Lade Klassen...</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="event-subject-id">Fach (optional)</label>
                                <select name="subject_id" id="event-subject-id">
                                    <option value="">Kein Fach</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="event-title">Titel* (Kurzbeschreibung)</label>
                                <input type="text" name="title" id="event-title" required placeholder="z.B. Test: 1. Quartal, S. 42 lesen">
                            </div>
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label for="event-due-date">Datum* (Fälligkeit/Termin)</label>
                                <input type="date" name="due_date" id="event-due-date" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label for="event-description">Beschreibung (optional)</label>
                                <textarea name="description" id="event-description" rows="2" placeholder="Weitere Details..."></textarea>
                            </div>
                            <div class="form-actions" style="margin-top: 0;">
                                <button type="submit" class="btn btn-primary btn-small" id="save-event-btn" style="width: auto;">Eintrag erstellen</button>
                                <span id="event-save-spinner" class="loading-spinner small" style="display: none;"></span>
                            </div>
                        </form>
                        
                        <h5>Meine Einträge (Nächste 14 Tage):</h5>
                        <div class="event-list-container" id="teacher-event-list">
                            <div class="loading-spinner small"></div>
                        </div>
                    </div>
                </div>

                <!-- Tab 8: Sprechzeiten verwalten -->
                <div class="dashboard-section" id="section-office-hours">
                    <div class="cockpit-feature" id="office-hours-feature">
                        <h4>Sprechstunden verwalten</h4>
                        <p class="form-hint" style="margin-bottom: 15px; margin-top: 0;">Definieren Sie hier Ihre wöchentlich wiederkehrenden Zeitfenster, die Schüler buchen können.</p>
                        <form id="office-hours-form" class="form-container" style="background-color: var(--color-surface-alt); padding: 15px; margin-bottom: 20px;">
                            <?php \App\Core\Security::csrfInput(); ?>
                            <div class="form-grid-col-3">
                                <div class="form-group">
                                    <label for="office-day">Wochentag*</label>
                                    <select name="day_of_week" id="office-day" required>
                                        <option value="1">Montag</option>
                                        <option value="2">Dienstag</option>
                                        <option value="3">Mittwoch</option>
                                        <option value="4">Donnerstag</option>
                                        <option value="5">Freitag</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="office-start-time">Von*</label>
                                    <input type="time" name="start_time" id="office-start-time" step="900" required>
                                </div>
                                <div class="form-group">
                                    <label for="office-end-time">Bis*</label>
                                    <input type="time" name="end_time" id="office-end-time" step="900" required>
                                </div>
                            </div>
                            <div class="form-group" style="max-width: 150px;">
                                <label for="office-slot-duration">Slot-Dauer (Min)*</label>
                                <input type="number" name="slot_duration" id="office-slot-duration" value="15" min="5" max="60" step="5" required>
                            </div>
                            <div class="form-actions" style="margin-top: 0;">
                                <button type="submit" class="btn btn-primary btn-small" id="save-office-hours-btn" style="width: auto;">Fenster hinzufügen</button>
                                <span id="office-hours-save-spinner" class="loading-spinner small" style="display: none;"></span>
                            </div>
                        </form>
                        <h5>Meine Sprechzeitenfenster:</h5>
                        <div class="office-hours-list-container" id="teacher-office-hours-list">
                            <div class="loading-spinner small"></div>
                        </div>
                    </div>
                </div>

                <!-- Tab 9: Kollegensuche -->
                <div class="dashboard-section" id="section-colleague-search">
                    <div class="cockpit-feature" id="find-colleague-feature">
                        <h4>Wo ist...? (Kollegensuche)</h4>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="colleague-search-input">Kollege/Kollegin suchen:</label>
                            <input type="text" id="colleague-search-input" placeholder="Name oder Kürzel suchen...">
                            <div class="search-results-dropdown" id="colleague-search-results">
                            </div>
                        </div>
                        <div class="search-result-display" id="colleague-result-display" style="display: none;">
                            <div class="loading-spinner small" style="display: none;"></div>
                            <p></p>
                        </div>
                    </div>
                </div>
                
                <!-- NEU: Community Board Tab für Lehrer (falls aktiviert) -->
                <?php if ($settings['community_board_enabled']): ?>
                <div class="dashboard-section" id="section-community-board">
                    <h4>Digitales Schwarzes Brett</h4>
                    <p class="form-hint" style="margin-bottom: 20px;">Informeller Feed für Fundsachen, AGs, Nachhilfe, etc.</p>

                    <form id="community-post-form" class="form-container" style="background-color: var(--color-surface-alt); padding: 15px; margin-bottom: 25px;">
                        <?php \App\Core\Security::csrfInput(); ?>
                        <h5>Neuen Beitrag erstellen</h5>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label for="post-title">Titel*</label>
                            <input type="text" id="post-title" name="title" required placeholder="z.B. Nachhilfe in Mathe gesucht">
                        </div>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label for="post-content">Inhalt*</label>
                            <textarea id="post-content" name="content" rows="4" required placeholder="Beschreibe dein Anliegen..."></textarea>
                            <small class="form-hint">Sie können <a href="https://www.markdownguide.org/basic-syntax/" target="_blank">Markdown</a> für die Formatierung verwenden.</small>
                        </div>
                        <div class="form-actions" style="margin-top: 0; justify-content: flex-end;">
                            <button type="submit" class="btn btn-primary btn-small" id="create-post-btn" style="width: auto;">Beitrag veröffentlichen</button>
                            <span id="post-create-spinner" class="loading-spinner small" style="display: none; margin-left: 10px;"></span>
                        </div>
                    </form>

                    <div id="community-posts-list" class="posts-list-container" style="max-height: 60vh; overflow-y: auto;">
                        <div class="loading-spinner"></div>
                    </div>
                </div>
                <?php endif; ?>
                
            </div> <?php // End #teacher-cockpit ?>
        <?php endif; ?>
        
    </div> <?php // End .tab-content ?>
</div> <?php // End .page-wrapper ?>


<?php // --- Modal für Stundenplan-Details (GILT FÜR ALLE) --- ?>
<div id="plan-detail-modal" class="modal-overlay">
    <div class="modal-box small-modal">
        <h2 id="plan-detail-title">Stunden-Details</h2>
        <div class="plan-detail-content">
            <div class="detail-row">
                <span class="detail-label">Status:</span>
                <span id="detail-status" class="detail-value"></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Zeit:</span>
                <span id="detail-time" class="detail-value"></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Fach:</span>
                <span id="detail-subject" class="detail-value"></span>
            </div>
            
            <?php // Dynamisch anzeigen, je nach Rolle ?>
            <?php if ($role === 'schueler'): ?>
            <div class="detail-row">
                <span class="detail-label">Lehrer:</span>
                <span id="detail-teacher" class="detail-value"></span>
            </div>
            <?php elseif ($role === 'lehrer'): ?>
             <div class="detail-row">
                <span class="detail-label">Klasse:</span>
                <span id="detail-class" class="detail-value"></span>
            </div>
            <?php endif; ?>
            
            <div class="detail-row">
                <span class="detail-label">Raum:</span>
                <span id="detail-room" class="detail-value"></span>
            </div>
            <div class="detail-row detail-comment" id="detail-comment-row" style="display: none;">
                <span class="detail-label">Kommentar:</span>
                <span id="detail-comment" class="detail-value"></span>
            </div>
            
            <?php // --- NEU: Platzhalter für Notizen (nur für Schüler) --- ?>
            <?php if ($role === 'schueler'): ?>
            <div class_alias="detail-row detail-notes" id="detail-notes-row" style="display: none; flex-direction: column; align-items: stretch; border-bottom: none; padding-bottom: 0;">
                <label for="detail-notes-input" class="detail-label" style="margin-bottom: 8px;">Meine private Notiz:</label>
                <textarea id="detail-notes-input" rows="3" placeholder="Notiz hinzufügen (z.B. Hausaufgaben, Material)..."></textarea>
                <span id="note-save-spinner" class="loading-spinner small" style="display: none; margin-top: 5px;"></span>
            </div>
            <?php endif; ?>
            
        </div>
        <div class="modal-actions" style="margin-top: 20px;">
            <button type="button" class="btn btn-secondary" id="plan-detail-close-btn">Schließen</button>
            <?php // --- NEU: Notiz-Speichern-Button (nur für Schüler) --- ?>
            <?php if ($role === 'schueler'): ?>
            <button type="button" class="btn btn-primary" id="plan-detail-save-note-btn">Notiz speichern</button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php // --- ENDE Modal --- ?>


<?php // --- NEU: Modal für "Meine Beiträge" (Bearbeiten) --- ?>
<?php if ($role === 'schueler' && $settings['community_board_enabled']): ?>
<div id="my-post-edit-modal" class="modal-overlay">
    <div class="modal-box">
        <button type="button" class="modal-close-btn" id="my-post-edit-close-btn">&times;</button>
        <h2 id="my-post-edit-title">Beitrag bearbeiten</h2>
        <form id="my-post-edit-form" class="form-container" style="padding: 0; border: none; background: none; box-shadow: none;">
            <input type="hidden" name="post_id" id="edit-post-id">
            
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="edit-post-title">Titel*</label>
                <input type="text" id="edit-post-title" name="title" required>
            </div>
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="edit-post-content">Inhalt*</label>
                <textarea id="edit-post-content" name="content" rows="6" required></textarea>
                <small class="form-hint">Der Beitrag wird nach dem Speichern erneut zur Moderation vorgelegt.</small>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" id="my-post-edit-cancel-btn">Abbrechen</button>
                <button type="submit" class="btn btn-primary" id="my-post-edit-save-btn">Speichern & Einreichen</button>
                <span id="my-post-edit-spinner" class="loading-spinner small" style="display: none; margin-left: 10px;"></span>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php // --- ENDE Modal --- ?>


<?php
include_once __DIR__ . '/partials/footer.php';
?>

