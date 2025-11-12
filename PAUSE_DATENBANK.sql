DROP DATABASE IF EXISTS pause_db;
-- Erstellung der Datenbank
CREATE DATABASE IF NOT EXISTS pause_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE pause_db;

-- -----------------------------------------------------------------------------
-- Tabelle: teachers (Lehrkräfte Stammdaten)
-- -----------------------------------------------------------------------------
CREATE TABLE teachers (
    teacher_id       INT          PRIMARY KEY AUTO_INCREMENT,
    teacher_shortcut VARCHAR(10)  NOT NULL UNIQUE COMMENT 'Eindeutiges Kürzel des Lehrers, z.B. "MUE"',
    first_name       VARCHAR(100) NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    email            VARCHAR(255) NULL COMMENT 'Optionale Kontakt-E-Mail'
) ENGINE=InnoDB COMMENT='Stammdaten der Lehrkräfte.';

-- -----------------------------------------------------------------------------
-- Tabelle: classes (Schulklassen Stammdaten)
-- -----------------------------------------------------------------------------
CREATE TABLE classes (
    class_id         INT          PRIMARY KEY,
    class_name       VARCHAR(20)  NOT NULL COMMENT 'Klassen-Akronym, z.B. "FIA".',
    class_teacher_id INT          NULL COMMENT 'FK zum Klassenlehrer in der teachers-Tabelle',
    FOREIGN KEY (class_teacher_id) REFERENCES teachers(teacher_id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Stammdaten der Schulklassen.';

-- -----------------------------------------------------------------------------
-- Tabelle: subjects (Unterrichtsfächer Stammdaten)
-- -----------------------------------------------------------------------------
CREATE TABLE subjects (
    subject_id       INT          PRIMARY KEY AUTO_INCREMENT,
    subject_name     VARCHAR(100) NOT NULL UNIQUE COMMENT 'Voller Name des Fachs, z.B. "Mathematik"',
    subject_shortcut VARCHAR(10)  NOT NULL UNIQUE COMMENT 'Kürzel des Fachs, z.B. "MA"'
) ENGINE=InnoDB COMMENT='Stammdaten der Unterrichtsfächer.';


-- -----------------------------------------------------------------------------
-- Tabelle: rooms (Raum Stammdaten)
-- -----------------------------------------------------------------------------
CREATE TABLE rooms (
    room_id   INT         PRIMARY KEY AUTO_INCREMENT,
    room_name VARCHAR(50) NOT NULL UNIQUE COMMENT 'Raumbezeichnung, z.B. "Raum 203"'
) ENGINE=InnoDB COMMENT='Stammdaten der Räume.';

-- -----------------------------------------------------------------------------
-- Tabelle: users (Zentrale Benutzerverwaltung)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    user_id       INT          PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL UNIQUE COMMENT 'Login-Name, z.B. "Max.Mustermann"',
    email         VARCHAR(255) NOT NULL UNIQUE COMMENT 'Eindeutige E-Mail für Login und Benachrichtigungen',
    password_hash VARCHAR(255) NOT NULL COMMENT 'Gehashtes Passwort (z.B. mit bcrypt)',
    role          ENUM('schueler', 'lehrer', 'planer', 'admin') NOT NULL COMMENT 'Benutzerrolle zur Rechteverwaltung',
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    birth_date    DATE         NULL COMMENT 'Geburtsdatum, hauptsächlich für Schüler',
    class_id      INT          NULL COMMENT 'FK zu classes. Nur für Schüler relevant.',
    teacher_id    INT          NULL COMMENT 'FK zu teachers. Nur für Lehrer-Accounts relevant.',
    is_community_banned TINYINT(1) NOT NULL DEFAULT 0,
    ical_token    VARCHAR(64)  NULL DEFAULT NULL UNIQUE COMMENT 'Sicherer Token für iCal-Feed',
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der Accounterstellung',
    CONSTRAINT fk_users_class FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE SET NULL,
    CONSTRAINT fk_users_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Zentrale Benutzertabelle für Authentifizierung und Benutzerdaten.';

-- -----------------------------------------------------------------------------
-- Tabelle: timetable_publish_status (Veröffentlichungsstatus pro Woche)
-- -----------------------------------------------------------------------------
CREATE TABLE timetable_publish_status (
    publish_id          INT PRIMARY KEY AUTO_INCREMENT,
    `year`              YEAR NOT NULL COMMENT 'Das Jahr der Kalenderwoche',
    calendar_week       TINYINT NOT NULL COMMENT 'Die Kalenderwoche (1-53)',
    target_group        ENUM('student', 'teacher') NOT NULL COMMENT 'Zielgruppe (Schüler oder Lehrer)',
    published_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Zeitpunkt der Veröffentlichung',
    publisher_user_id   INT NULL COMMENT 'FK zum Benutzer, der veröffentlicht hat',
    FOREIGN KEY (publisher_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY `unique_publish` (`year`, `calendar_week`, `target_group`)
) ENGINE=InnoDB COMMENT='Speichert, welche Wochenpläne für welche Gruppen veröffentlicht sind.';

-- -----------------------------------------------------------------------------
-- Tabelle: timetable_entries (Versionierter, regulärer Stundenplan)
-- -----------------------------------------------------------------------------
CREATE TABLE timetable_entries (
    entry_id      INT     PRIMARY KEY AUTO_INCREMENT,
    `year`        YEAR    NOT NULL COMMENT 'Das Gültigkeitsjahr des Eintrags',
    calendar_week TINYINT NOT NULL COMMENT 'Die Kalenderwoche (1-53) der Gültigkeit',
    day_of_week   TINYINT NOT NULL COMMENT '1=Montag, 2=Dienstag, ..., 5=Freitag',
    period_number TINYINT NOT NULL COMMENT 'Die jeweilige Unterrichtsstunde (z.B. 1 bis 10)',
    class_id      INT     NOT NULL,
    teacher_id    INT     NOT NULL,
    subject_id    INT     NOT NULL,
    room_id       INT     NOT NULL,
    block_id      VARCHAR(50) NULL DEFAULT NULL COMMENT 'Eine eindeutige ID, die zusammengehörige Blockstunden gruppiert.',
    `comment`     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optionaler Kommentar zur regulären Stunde',
    FOREIGN KEY (class_id)   REFERENCES classes(class_id)   ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id)    REFERENCES rooms(room_id)      ON DELETE CASCADE,
    UNIQUE KEY `unique_course_in_timeslot` (`year`, `calendar_week`, `day_of_week`, `period_number`, `class_id`),
    INDEX `idx_timetable_week` (`year`, `calendar_week`, `day_of_week`, `period_number`),
    INDEX `idx_block_id` (`block_id`)
) ENGINE=InnoDB COMMENT='Speichert die versionierten, wöchentlichen Stundenplaneinträge.';


-- -----------------------------------------------------------------------------
-- Tabelle: substitutions (Vertretungsplan-Einträge)
-- -----------------------------------------------------------------------------
CREATE TABLE substitutions (
    substitution_id     INT PRIMARY KEY AUTO_INCREMENT,
    date                DATE NOT NULL COMMENT 'Datum, an dem die Vertretung stattfindet',
    period_number       TINYINT NOT NULL COMMENT 'Die betroffene Unterrichtsstunde',
    class_id            INT NOT NULL,
    substitution_type   ENUM('Vertretung', 'Raumänderung', 'Entfall', 'Sonderevent') NOT NULL,
    original_subject_id INT NULL COMMENT 'FK zum ursprünglich geplanten Fach',
    new_teacher_id      INT NULL COMMENT 'FK zum vertretenden Lehrer',
    new_subject_id      INT NULL COMMENT 'FK zum neuen Fach (falls Fach geändert)',
    new_room_id         INT NULL COMMENT 'FK zum neuen Raum (falls Raum geändert)',
    comment             VARCHAR(255) NULL COMMENT 'Zusätzliche Informationen',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id)            REFERENCES classes(class_id)   ON DELETE CASCADE,
    FOREIGN KEY (original_subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL,
    FOREIGN KEY (new_teacher_id)      REFERENCES teachers(teacher_id) ON DELETE SET NULL,
    FOREIGN KEY (new_subject_id)      REFERENCES subjects(subject_id) ON DELETE SET NULL,
    FOREIGN KEY (new_room_id)         REFERENCES rooms(room_id)       ON DELETE SET NULL,
    INDEX `idx_substitutions_date` (`date`, `period_number`)
) ENGINE=InnoDB COMMENT='Speichert alle Vertretungen und Planänderungen.';

CREATE TABLE `login_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempt_time` datetime NOT NULL,
  PRIMARY KEY (`attempt_id`),
  KEY `identifier_idx` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für Ankündigungen (angepasst an das Schema von vorhin)
CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'The user who created the announcement',
  `class_id` int(11) DEFAULT NULL COMMENT 'The target class for the announcement, NULL if global',
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `file_path` VARCHAR(512) NULL DEFAULT NULL COMMENT 'Path to attachment',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_global` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 for global announcements, 0 for class-specific',
  PRIMARY KEY (`announcement_id`),
  KEY `user_id_fk` (`user_id`),
  KEY `class_id_fk` (`class_id`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tabelle: timetable_templates (Stundenplan-Vorlagen)
-- -----------------------------------------------------------------------------
CREATE TABLE timetable_templates (
    template_id INT PRIMARY KEY AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB COMMENT='Speichert die Stammdaten für Stundenplan-Vorlagen.';

-- -----------------------------------------------------------------------------
-- Tabelle: timetable_template_entries (Einträge für Vorlagen)
-- -----------------------------------------------------------------------------
CREATE TABLE timetable_template_entries (
    template_entry_id INT PRIMARY KEY AUTO_INCREMENT,
    template_id       INT NOT NULL,
    day_of_week       TINYINT NOT NULL COMMENT '1=Montag, ..., 5=Freitag',
    period_number     TINYINT NOT NULL,
    class_id          INT NOT NULL COMMENT 'Standard-Klasse für diesen Eintrag',
    teacher_id        INT NOT NULL,
    subject_id        INT NOT NULL,
    room_id           INT NOT NULL,
    block_ref         VARCHAR(50) NULL DEFAULT NULL COMMENT 'Eindeutige Referenz (pro Vorlage) für Blockstunden',
    `comment`         VARCHAR(255) NULL DEFAULT NULL,
    FOREIGN KEY (template_id) REFERENCES timetable_templates(template_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)    REFERENCES classes(class_id)   ON DELETE CASCADE,
    FOREIGN KEY (teacher_id)  REFERENCES teachers(teacher_id)  ON DELETE CASCADE,
    FOREIGN KEY (subject_id)  REFERENCES subjects(subject_id)  ON DELETE CASCADE,
    FOREIGN KEY (room_id)     REFERENCES rooms(room_id)      ON DELETE CASCADE,
    INDEX `idx_template_id` (`template_id`)
) ENGINE=InnoDB COMMENT='Speichert die einzelnen Einträge einer Stundenplan-Vorlage.';


-- -----------------------------------------------------------------------------
-- Tabelle: audit_logs (Protokollierung)
-- -----------------------------------------------------------------------------
CREATE TABLE audit_logs (
    log_id      INT PRIMARY KEY AUTO_INCREMENT,
    user_id     INT NULL COMMENT 'FK zu users. NULL bei System-Aktionen (z.B. fehlgeschlagener Login).',
    ip_address  VARCHAR(45) NULL,
    `action`    VARCHAR(100) NOT NULL COMMENT 'Aktion, z.B. "login_success", "user_create", "plan_update"',
    target_type VARCHAR(50) NULL COMMENT 'Typ des Ziels, z.B. "user", "class", "plan_entry"',
    target_id   VARCHAR(255) NULL COMMENT 'ID des Ziels (kann INT oder VARCHAR sein, z.B. block_id)',
    details     TEXT NULL COMMENT 'JSON-kodierte Details (z.B. alte/neue Daten)',
    `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX `idx_action` (`action`),
    INDEX `idx_target` (`target_type`, `target_id`),
    INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB COMMENT='Protokolliert wichtige Aktionen im System.';

CREATE TABLE attendance_logs (
    attendance_id     INT PRIMARY KEY AUTO_INCREMENT,
    `date`            DATE NOT NULL COMMENT 'Datum des Eintrags',
    period_number     TINYINT NOT NULL COMMENT 'Unterrichtsstunde',
    class_id          INT NOT NULL COMMENT 'Klasse, die unterrichtet wurde',
    student_user_id   INT NOT NULL COMMENT 'FK zum anwesenden/fehlenden Schüler',
    teacher_user_id   INT NOT NULL COMMENT 'FK zum Lehrer, der die Anwesenheit erfasst hat',
    `status`          ENUM('anwesend', 'abwesend', 'verspaetet', 'entschuldigt') NOT NULL DEFAULT 'anwesend',
    `timestamp`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (student_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    -- Verhindert doppelte Einträge pro Schüler pro Stunde
    UNIQUE KEY `unique_attendance` (`date`, `period_number`, `student_user_id`),
    
    INDEX `idx_class_period` (`class_id`, `date`, `period_number`)
) ENGINE=InnoDB COMMENT='Protokolliert die Anwesenheit der Schüler.';

-- -----------------------------------------------------------------------------
-- Tabelle: settings (Anwendungs-Einstellungen)
-- -----------------------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    last_updated  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Speichert globale Anwendungs-Einstellungen.';

-- Standard-Einstellungen einfügen
INSERT INTO settings (setting_key, setting_value) VALUES
('site_title', 'PAUSE Portal'),
('maintenance_mode', '0'),
('default_start_hour', '1'),
('default_end_hour', '10')
ON DUPLICATE KEY UPDATE setting_key=setting_key; -- Mache nichts, falls schon vorhanden
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- Tabelle: academic_events (Aufgaben, Klausuren, etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE academic_events (
    event_id      INT PRIMARY KEY AUTO_INCREMENT,
    user_id       INT NOT NULL COMMENT 'FK zu users: Der Lehrer, der das Event erstellt hat',
    class_id      INT NOT NULL COMMENT 'FK zu classes: Die Ziel-Klasse',
    subject_id    INT NULL COMMENT 'FK zu subjects: Optionales Fach',
    event_type    ENUM('aufgabe', 'klausur', 'info') NOT NULL DEFAULT 'info' COMMENT 'Art des Eintrags',
    title         VARCHAR(255) NOT NULL COMMENT 'Kurzer Titel, z.B. "Test: 1. Quartal"',
    description   TEXT NULL COMMENT 'Optionale längere Beschreibung',
    due_date      DATE NOT NULL COMMENT 'Datum der Fälligkeit oder des Termins',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE SET NULL,
    
    INDEX `idx_event_due_date` (`due_date`),
    INDEX `idx_event_class` (`class_id`)
) ENGINE=InnoDB COMMENT='Speichert Aufgaben, Klausuren und Infos für Klassen.';

-- -----------------------------------------------------------------------------
-- Tabelle: teacher_availability (Regelmäßige Sprechzeiten-Fenster)
-- -----------------------------------------------------------------------------
CREATE TABLE teacher_availability (
    availability_id  INT PRIMARY KEY AUTO_INCREMENT,
    teacher_user_id  INT NOT NULL COMMENT 'FK zu users (Lehrer)',
    day_of_week      TINYINT NOT NULL COMMENT '1=Montag, ..., 5=Freitag',
    start_time       TIME NOT NULL COMMENT 'z.B. 14:00:00',
    end_time         TIME NOT NULL COMMENT 'z.B. 15:00:00',
    slot_duration    INT NOT NULL DEFAULT 15 COMMENT 'Dauer eines Slots in Minuten (z.B. 15)',
    location         VARCHAR(100) NULL DEFAULT NULL COMMENT 'Raum oder Ort (z.B. Online, R201)',
    
    FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    -- Ein Lehrer kann pro Wochentag mehrere Fenster definieren, aber nicht überlappend
    -- (Eine einfache Unique-Constraint (user, day, start) reicht für die meisten Fälle)
    UNIQUE KEY `unique_teacher_timeslot` (`teacher_user_id`, `day_of_week`, `start_time`)
) ENGINE=InnoDB COMMENT='Definiert die regelmäßigen Sprechstundenfenster der Lehrer.';

-- -----------------------------------------------------------------------------
-- Tabelle: appointments (Gebuchte Sprechstunden-Termine)
-- -----------------------------------------------------------------------------
CREATE TABLE appointments (
    appointment_id    INT PRIMARY KEY AUTO_INCREMENT,
    teacher_user_id   INT NOT NULL COMMENT 'FK zu users (Lehrer)',
    student_user_id   INT NOT NULL COMMENT 'FK zu users (Schüler)',
    availability_id   INT NOT NULL COMMENT 'FK zur gebuchten Verfügbarkeit',
    appointment_date  DATE NOT NULL COMMENT 'Konkretes Datum des Termins',
    appointment_time  TIME NOT NULL COMMENT 'Konkrete Startzeit des Termins (z.B. 14:15:00)',
    duration          INT NOT NULL COMMENT 'Dauer des Termins in Minuten (aus availability)',
    location          VARCHAR(100) NULL DEFAULT NULL COMMENT 'Gebuchter Raum/Ort zum Zeitpunkt der Buchung',
    notes             TEXT NULL COMMENT 'Optionale Notiz des Schülers',
    status            ENUM('booked', 'cancelled_by_teacher', 'cancelled_by_student') NOT NULL DEFAULT 'booked',
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (teacher_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (student_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (availability_id) REFERENCES teacher_availability(availability_id) ON DELETE CASCADE,
    
    -- Verhindert, dass ein Slot (Lehrer, Datum, Zeit) doppelt gebucht wird
    UNIQUE KEY `unique_appointment_slot` (`teacher_user_id`, `appointment_date`, `appointment_time`),
    
    INDEX `idx_student_app` (`student_user_id`, `appointment_date`),
    INDEX `idx_teacher_app` (`teacher_user_id`, `appointment_date`)
) ENGINE=InnoDB COMMENT='Speichert die gebuchten Sprechstunden-Termine.';

CREATE TABLE IF NOT EXISTS community_posts (
    post_id       INT PRIMARY KEY AUTO_INCREMENT,
    user_id       INT NOT NULL COMMENT 'FK zu users (Ersteller)',
    title         VARCHAR(255) NOT NULL,
    content       TEXT NOT NULL,
    status        ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    moderator_id  INT NULL COMMENT 'FK zu users (Admin/Planer, der moderiert hat)',
    moderated_at  TIMESTAMP NULL DEFAULT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (moderator_id) REFERENCES users(user_id) ON DELETE SET NULL,
    
    INDEX `idx_status_date` (`status`, `created_at`)
) ENGINE=InnoDB COMMENT='Speichert Beiträge für das Schwarze Brett.' CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Tabelle: student_notes (Private Notizen von Schülern)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_notes (
    note_id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NOT NULL COMMENT 'FK zu users (Schüler-ID)',
    `year`          YEAR NOT NULL COMMENT 'Jahr der Notiz',
    calendar_week   TINYINT NOT NULL COMMENT 'Kalenderwoche der Notiz',
    day_of_week     TINYINT NOT NULL COMMENT '1=Montag, ..., 5=Freitag',
    period_number   TINYINT NOT NULL COMMENT 'Stunde der Notiz',
    note_content    TEXT NULL COMMENT 'Inhalt der privaten Notiz',
    last_updated    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    
    -- Stellt sicher, dass ein Schüler nur eine Notiz pro Slot hat
    UNIQUE KEY `unique_user_slot_note` (`user_id`, `year`, `calendar_week`, `day_of_week`, `period_number`),
    
    INDEX `idx_user_week` (`user_id`, `year`, `calendar_week`)
) ENGINE=InnoDB COMMENT='Speichert private Notizen von Schülern zu Stundenplan-Slots.' CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SQL-Anweisung zur Erstellung der Tabelle 'teacher_absences'
-- Diese Tabelle speichert geplante Abwesenheiten für Lehrer.

CREATE TABLE `teacher_absences` (
  `absence_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Eindeutige ID der Abwesenheit',
  `teacher_id` int(11) NOT NULL COMMENT 'FK zur teachers Tabelle',
  `start_date` date NOT NULL COMMENT 'Erster Tag der Abwesenheit (inklusiv)',
  `end_date` date NOT NULL COMMENT 'Letzter Tag der Abwesenheit (inklusiv)',
  `reason` varchar(100) NOT NULL COMMENT 'z.B. Krank, Fortbildung, Beurlaubt',
  `comment` text DEFAULT NULL COMMENT 'Optionale Notiz zur Abwesenheit',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Zeitstempel der Erstellung',
  `planner_user_id` int(11) DEFAULT NULL COMMENT 'FK zur users Tabelle (Wer hat dies eingetragen?)',
  
  PRIMARY KEY (`absence_id`),
  
  KEY `idx_teacher_id` (`teacher_id`),
  KEY `idx_date_range` (`start_date`,`end_date`),
  
  CONSTRAINT `fk_teacher_absences_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_teacher_absences_planner` FOREIGN KEY (`planner_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Fügt die globale Einstellung hinzu (falls nicht schon vorhanden)
INSERT INTO `settings` (setting_key, setting_value)
VALUES ('community_board_enabled', '1')
ON DUPLICATE KEY UPDATE setting_value = setting_value;



-- ### SEED DATA ###

-- 1. Lehrer anlegen (Fortsetzung)
INSERT INTO `teachers` (`teacher_id`, `teacher_shortcut`, `first_name`, `last_name`, `email`) VALUES
(1, 'ROY', 'Abilash', 'Roy Nalpatamkalam', NULL),
(2, 'BOE', 'Sebastian', 'Böttcher', 'sebastian.boettcher2@srh.de'),
(3, 'PAU', 'Steven', 'Pauls', 'Steven.Pauls@srh.de'),
(4, 'KUE', 'Timo', 'Kuehnel', 'timo.kuehnel@srh.de'),
(5, 'GAR', 'Julio', 'Garcia da Silva', 'juliocezar.garciadasilva@srh.de'),
(6, 'SGL', 'Selbstgesteuertes', 'Lernen', NULL),
(7, 'SCW', 'Manfred', 'Schwab', 'manfred.schwab@srh.de'),
(8, 'MIT', 'Mike', 'Mitsch', 'mike.mitsch@srh.de'),
(9, 'BOA', 'Andrea', 'Bock', 'andrea.bock@srh.de'),
(10, 'KRO', 'Silke', 'Kropp', 'silke.kropp@srh.de'),
(11, 'CRA', 'Catarina', 'Cramer', 'Catarina.Cramer@srh.de'),
(12, 'DIL', 'TuUyen', 'Dillinger', 'TuUyen.Dillinger@srh.de'),
(13, 'BRO', 'Martin', 'Broutschek', 'martin.broutschek@srh.de'),
(14, 'DAM', 'Alexander', 'Damm', 'alexander.damm@srh.de'),
(15, 'DUE', 'Sebastian', 'Duerr', 'sebastian.duerr@srh.de'),
(16, 'FAB', 'Martin', 'Faber', 'martin.faber@srh.de'),
(17, 'FIS', 'Klaus', 'Fischer', 'klaus.fischer@srh.de'),
(18, 'HOO', 'Peter', 'Hoock', 'peter.hoock@srh.de'),
(19, 'HAR', 'Georg', 'Hartmann', 'georg.hartmann@srh.de'),
(20, 'KOL', 'Hein', 'Kolster', 'hein.kolster@srh.de'),
(21, 'KAS', 'Florian', 'Kast', 'florian.kast@srh.de'),
(22, 'PET', 'Dirk', 'Petrik', 'dirk.petrik@srh.de'),
(23, 'SCH', 'Norbert', 'Scharfe', 'norbert.scharfe@srh.de'),
(24, 'VIR', 'Stefan', 'Virag', 'stefan.virag@srh.de')
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    email = VALUES(email);

-- 4. Fächer anlegen (Korrigiert)
INSERT INTO `subjects` (`subject_shortcut`, `subject_name`) VALUES
('LO', 'Lernorganistor'),
('BWL', 'Betriebswirtschaftslehre'),
('INF', 'Informatik'),
('GdP/Java', 'Grundlagen der Programmierung/Java'),
('JAVA', 'Java'),
('WEB', 'Webentwicklung'),
('DB', 'Datenbanken'),
('DBK', 'Deutsch und Betriebliche Kommunikation'),
('ENG', 'Englisch'),
('ENG A1', 'Englisch A1'),
('ENG A2', 'Englisch A2'),
('ENG B1', 'Englisch B1'),
('MAT', 'Mathe'),
('Do-It', 'DoIT'),
('SAP', 'SAP'),
('SYS WIN', 'Systeme Windows'),
('SYS NET', 'Syteme Netzwerktechnik'),
('SYS LIN', 'Systeme Linux'),
('BWSS', 'Betriebswirtschaftliche Standardsoftware'),
('INC', 'Bewerbungstraining'), -- KORRIGIERT
('CAD', 'CAD'),
('SGL', 'Selbstgesteuertes Lernen')
ON DUPLICATE KEY UPDATE
    subject_name = VALUES(subject_name);

-- 5. Räume anlegen (Korrigiert)
INSERT INTO `rooms` (`room_name`) VALUES
('BS06-301'),
('BS06-302'),
('BS06-303'),
('BS06-304'),
('BS06-305'),
('BS06-E01'),
('BS06-102'),
('BS06-103'),
('BS06-104')
ON DUPLICATE KEY UPDATE
    room_name = VALUES(room_name);

-- 2. Klassen anlegen
INSERT INTO `classes` (`class_id`, `class_name`, `class_teacher_id`) VALUES
(2341, 'FIA-23/1', NULL),
(2342, 'FIA-23/2', NULL),
(2351, 'FIA-23/3', NULL),
(2441, 'FIS-24/1', NULL),
(2442, 'FIS-24/2', NULL),
(2451, 'FIS-23/1', NULL),
(2452, 'FIS-23/2', NULL),
(2541, 'WI-24/1', NULL),
(2551, 'WI-23/1', NULL),
(3241, 'QF-24/1', NULL),
(3242, 'QF-24/2', NULL),
(3251, 'QF-23/1', NULL),
(3252, 'QF-23/2', NULL),
(3441, 'TPD-24/1', NULL),
(3442, 'TPD-24/2', NULL),
(3451, 'TPD-23/1', NULL),
(3452, 'TPD-23/2', NULL)
ON DUPLICATE KEY UPDATE
    class_name = VALUES(class_name),
    class_teacher_id = VALUES(class_teacher_id);

-- 3. Benutzer anlegen
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `class_id`, `teacher_id`)
VALUES 
(1, 'admin', 'admin@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'admin', 'Admin', 'User', NULL, NULL),
(2, 'planer', 'planer@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'planer', 'Planer', 'User', NULL, NULL),
(3, 'abilash.roy', 'abilash.roy@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Abilash', 'Roy Nalpatamkalam', NULL, 1),
(4, 'susi.schueler', 'susi.schueler@schule.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Susi', 'Schüler', 2341, NULL),
(5, 'max.mustermann', 'max.mustermann@schule.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Max', 'Mustermann', 2342, NULL),
(6, 'erika.musterfrau', 'erika.musterfrau@schule.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Erika', 'Musterfrau', 2441, NULL),
(7, 'sebastian.boettcher', 'sebastian.boettcher2@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Sebastian', 'Böttcher', NULL, 2),
(8, 'steven.pauls', 'steven.pauls@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Steven', 'Pauls', NULL, 3),
(9, 'tuuyen.dillinger', 'TuUyen.Dillinger@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'TuUyen', 'Dillinger', NULL, 12),
(10, 'martin.broutschek', 'martin.broutschek@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Martin', 'Broutschek', NULL, 13),
(11, 'alexander.damm', 'alexander.damm@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Alexander', 'Damm', NULL, 14),
(12, 'sebastian.duerr', 'sebastian.duerr@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Sebastian', 'Duerr', NULL, 15),
(13, 'martin.faber', 'martin.faber@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Martin', 'Faber', NULL, 16),
(14, 'klaus.fischer', 'klaus.fischer@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Klaus', 'Fischer', NULL, 17),
(15, 'peter.hoock', 'peter.hoock@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Peter', 'Hoock', NULL, 18),
(16, 'georg.hartmann', 'georg.hartmann@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Georg', 'Hartmann', NULL, 19),
(17, 'hein.kolster', 'hein.kolster@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Hein', 'Kolster', NULL, 20),
(18, 'florian.kast', 'florian.kast@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Florian', 'Kast', NULL, 21),
(19, 'dirk.petrik', 'dirk.petrik@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Dirk', 'Petrik', NULL, 22),
(20, 'norbert.scharfe', 'norbert.scharfe@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Norbert', 'Scharfe', NULL, 23),
(21, 'stefan.virag', 'stefan.virag@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Stefan', 'Virag', NULL, 24),
(22, 'timo.kuehnel', 'timo.kuehnel@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Timo', 'Kuehnel', NULL, 4),
(23, 'julio.garcia', 'juliocezar.garciadasilva@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Julio', 'Garcia da Silva', NULL, 5),
(24, 'manfred.schwab', 'manfred.schwab@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Manfred', 'Schwab', NULL, 7),
(25, 'mike.mitsch', 'mike.mitsch@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Mike', 'Mitsch', NULL, 8),
(26, 'andrea.bock', 'andrea.bock@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Andrea', 'Bock', NULL, 9),
(27, 'silke.kropp', 'silke.kropp@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Silke', 'Kropp', NULL, 10),
(28, 'catarina.cramer', 'Catarina.Cramer@srh.de', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'lehrer', 'Catarina', 'Cramer', NULL, 11)
ON DUPLICATE KEY UPDATE
    username = VALUES(username),
    email = VALUES(email),
    role = VALUES(role),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    class_id = VALUES(class_id),
    teacher_id = VALUES(teacher_id);

-- Student user accounts
INSERT INTO `users` (`user_id`, `username`, `email`, `password_hash`, `role`, `first_name`, `last_name`, `class_id`, `teacher_id`) VALUES
(29, 'student.2341', 'student.2341@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Anna', 'AzubiFIA1', 2341, NULL),
(30, 'student.2342', 'student.2342@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Ben', 'LehrlingFIA2', 2342, NULL),
(31, 'student.2351', 'student.2351@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Clara', 'TraineeFIA3', 2351, NULL),
(32, 'student.2441', 'student.2441@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'David', 'PraktikantFIS1', 2441, NULL),
(33, 'student.2442', 'student.2442@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Elena', 'AzubiFIS2', 2442, NULL),
(34, 'student.2451', 'student.2451@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Finn', 'LehrlingFIS3', 2451, NULL),
(35, 'student.2452', 'student.2452@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Greta', 'TraineeFIS4', 2452, NULL),
(36, 'student.2541', 'student.2541@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Hugo', 'AzubiWI1', 2541, NULL),
(37, 'student.2551', 'student.2551@pause.local', '$2y$10$LVYG9cdULLOBWp29HywZsO1Xh8e5tMQ0aqB177aHc.dT32UYlxRt.', 'schueler', 'Helga', 'AzubiWI1', 2541, NULL)
ON DUPLICATE KEY UPDATE username=username; -- Do nothing if user already exists


INSERT INTO `timetable_entries` (`year`, `calendar_week`, `day_of_week`, `period_number`, `class_id`, `teacher_id`, `subject_id`, `room_id`, `block_id`, `comment`) VALUES
-- KW 44 / 2025
-- Monday
(2025, 44, 1, 2, 2341, 2, 4, 1, 'blk_kw44_mo_2341_1', NULL), -- BOE GdP/Java R301 Block
(2025, 44, 1, 3, 2341, 2, 4, 1, 'blk_kw44_mo_2341_1', NULL), -- BOE GdP/Java R301 Block
(2025, 44, 1, 4, 2341, 10, 8, 2, NULL, NULL), -- KRO DBK R302
(2025, 44, 1, 5, 2341, 17, 13, 2, NULL, NULL), -- FIS MAT R302
(2025, 44, 1, 6, 2341, 5, 9, 3, 'blk_kw44_mo_2341_2', NULL), -- GAR ENG R303 Block
(2025, 44, 1, 7, 2341, 5, 9, 3, 'blk_kw44_mo_2341_2', NULL), -- GAR ENG R303 Block
(2025, 44, 1, 8, 2341, 1, 3, 4, NULL, NULL), -- ROY INF R304
(2025, 44, 1, 9, 2341, 1, 3, 4, NULL, NULL), -- ROY INF R304
(2025, 44, 1, 2, 2441, 14, 2, 5, 'blk_kw44_mo_2441_1', NULL), -- DAM BWL R305 Block
(2025, 44, 1, 3, 2441, 14, 2, 5, 'blk_kw44_mo_2441_1', NULL), -- DAM BWL R305 Block
(2025, 44, 1, 4, 2441, 21, 17, 6, NULL, NULL), -- KAS SYS NET E01
(2025, 44, 1, 5, 2441, 15, 8, 6, NULL, NULL), -- DUE DBK E01
(2025, 44, 1, 6, 2441, 19, 9, 7, NULL, NULL), -- HAR ENG 102
(2025, 44, 1, 7, 2441, 18, 16, 7, 'blk_kw44_mo_2441_2', NULL), -- HOO SYS WIN 102 Block
(2025, 44, 1, 8, 2441, 18, 16, 7, 'blk_kw44_mo_2441_2', NULL), -- HOO SYS WIN 102 Block
(2025, 44, 1, 9, 2441, 1, 1, 8, NULL, 'Lernorga'), -- ROY LO 103
(2025, 44, 1, 2, 2541, 16, 15, 9, 'blk_kw44_mo_2541_1', NULL), -- FAB SAP 104 Block
(2025, 44, 1, 3, 2541, 16, 15, 9, 'blk_kw44_mo_2541_1', NULL), -- FAB SAP 104 Block
(2025, 44, 1, 4, 2541, 9, 2, 1, 'blk_kw44_mo_2541_2', NULL), -- BOA BWL R301 Block
(2025, 44, 1, 5, 2541, 9, 2, 1, 'blk_kw44_mo_2541_2', NULL), -- BOA BWL R301 Block
(2025, 44, 1, 6, 2541, 24, 8, 2, NULL, NULL), -- VIR DBK R302
(2025, 44, 1, 7, 2541, 7, 9, 2, NULL, NULL), -- SCW ENG R302
(2025, 44, 1, 8, 2541, 17, 13, 3, NULL, NULL), -- FIS MAT R303
(2025, 44, 1, 9, 2541, 17, 13, 3, NULL, NULL), -- FIS MAT R303
(2025, 44, 1, 2, 2342, 4, 2, 4, NULL, NULL), -- KUE BWL R304
(2025, 44, 1, 3, 2342, 4, 2, 4, NULL, NULL), -- KUE BWL R304
(2025, 44, 1, 6, 2342, 22, 3, 5, 'blk_kw44_mo_2342_1', NULL), -- PET INF R305 Block
(2025, 44, 1, 7, 2342, 22, 3, 5, 'blk_kw44_mo_2342_1', NULL), -- PET INF R305 Block
(2025, 44, 1, 8, 2342, 11, 8, 6, NULL, NULL), -- CRA DBK E01
(2025, 44, 1, 9, 2342, 11, 8, 6, NULL, NULL), -- CRA DBK E01
(2025, 44, 1, 2, 2442, 23, 7, 7, NULL, NULL), -- SCH DB 102
(2025, 44, 1, 3, 2442, 23, 7, 7, NULL, NULL), -- SCH DB 102
(2025, 44, 1, 4, 2442, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 44, 1, 5, 2442, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 44, 1, 6, 2442, 15, 2, 9, NULL, NULL), -- DUE BWL 104
(2025, 44, 1, 7, 2442, 15, 2, 9, NULL, NULL), -- DUE BWL 104
(2025, 44, 1, 8, 2442, 12, 9, 1, NULL, NULL), -- DIL ENG R301
(2025, 44, 1, 9, 2442, 12, 9, 1, NULL, NULL), -- DIL ENG R301

-- Tuesday
(2025, 44, 2, 2, 2341, 3, 6, 1, 'blk_kw44_di_2341_1', NULL), -- PAU WEB R301 Block
(2025, 44, 2, 3, 2341, 3, 6, 1, 'blk_kw44_di_2341_1', NULL), -- PAU WEB R301 Block
(2025, 44, 2, 4, 2341, 20, 7, 2, 'blk_kw44_di_2341_2', NULL), -- KOL DB R302 Block
(2025, 44, 2, 5, 2341, 20, 7, 2, 'blk_kw44_di_2341_2', NULL), -- KOL DB R302 Block
(2025, 44, 2, 6, 2341, 4, 2, 3, NULL, NULL), -- KUE BWL R303
(2025, 44, 2, 7, 2341, 4, 2, 3, NULL, NULL), -- KUE BWL R303
(2025, 44, 2, 8, 2341, 18, 16, 4, NULL, NULL), -- HOO SYS WIN R304
(2025, 44, 2, 9, 2341, 18, 16, 4, NULL, NULL), -- HOO SYS WIN R304
(2025, 44, 2, 2, 2441, 8, 2, 5, NULL, NULL), -- MIT BWL R305
(2025, 44, 2, 3, 2441, 8, 2, 5, NULL, NULL), -- MIT BWL R305
(2025, 44, 2, 4, 2441, 11, 8, 6, NULL, NULL), -- CRA DBK E01
(2025, 44, 2, 5, 2441, 11, 8, 6, NULL, NULL), -- CRA DBK E01
(2025, 44, 2, 6, 2441, 23, 7, 7, 'blk_kw44_di_2441_1', NULL), -- SCH DB 102 Block
(2025, 44, 2, 7, 2441, 23, 7, 7, 'blk_kw44_di_2441_1', NULL), -- SCH DB 102 Block
(2025, 44, 2, 8, 2441, 1, 3, 8, NULL, NULL), -- ROY INF 103
(2025, 44, 2, 9, 2441, 1, 3, 8, NULL, NULL), -- ROY INF 103
(2025, 44, 2, 2, 2541, 13, 13, 9, NULL, NULL), -- BRO MAT 104
(2025, 44, 2, 3, 2541, 13, 13, 9, NULL, NULL), -- BRO MAT 104
(2025, 44, 2, 4, 2541, 1, 1, 1, NULL, NULL), -- ROY LO R301
(2025, 44, 2, 5, 2541, 1, 1, 1, NULL, NULL), -- ROY LO R301
(2025, 44, 2, 6, 2541, 19, 9, 2, NULL, NULL), -- HAR ENG R302
(2025, 44, 2, 7, 2541, 19, 9, 2, NULL, NULL), -- HAR ENG R302
(2025, 44, 2, 8, 2541, 2, 2, 3, NULL, NULL), -- BOE BWL R303
(2025, 44, 2, 9, 2541, 2, 2, 3, NULL, NULL), -- BOE BWL R303
(2025, 44, 2, 4, 2451, 12, 9, 4, 'blk_kw44_di_2451_1', NULL), -- DIL ENG R304 Block
(2025, 44, 2, 5, 2451, 12, 9, 4, 'blk_kw44_di_2451_1', NULL), -- DIL ENG R304 Block
(2025, 44, 2, 6, 2451, 16, 17, 5, NULL, NULL), -- FAB SYS NET R305
(2025, 44, 2, 7, 2451, 16, 17, 5, NULL, NULL), -- FAB SYS NET R305
(2025, 44, 2, 8, 2451, 24, 7, 6, NULL, NULL), -- VIR DB E01
(2025, 44, 2, 9, 2451, 24, 7, 6, NULL, NULL), -- VIR DB E01
(2025, 44, 2, 2, 2551, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 44, 2, 3, 2551, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 44, 2, 4, 2551, 21, 15, 8, 'blk_kw44_di_2551_1', NULL), -- KAS SAP 103 Block
(2025, 44, 2, 5, 2551, 21, 15, 8, 'blk_kw44_di_2551_1', NULL), -- KAS SAP 103 Block
(2025, 44, 2, 6, 2551, 14, 2, 9, NULL, NULL), -- DAM BWL 104
(2025, 44, 2, 7, 2551, 14, 2, 9, NULL, NULL), -- DAM BWL 104

-- Wednesday
(2025, 44, 3, 2, 2341, 1, 3, 1, 'blk_kw44_mi_2341_1', NULL), -- ROY INF R301 Block
(2025, 44, 3, 3, 2341, 1, 3, 1, 'blk_kw44_mi_2341_1', NULL), -- ROY INF R301 Block
(2025, 44, 3, 4, 2341, 17, 13, 2, NULL, NULL), -- FIS MAT R302
(2025, 44, 3, 5, 2341, 17, 13, 2, NULL, NULL), -- FIS MAT R302
(2025, 44, 3, 6, 2341, 10, 8, 3, NULL, NULL), -- KRO DBK R303
(2025, 44, 3, 7, 2341, 10, 8, 3, NULL, NULL), -- KRO DBK R303
(2025, 44, 3, 8, 2341, 3, 6, 4, NULL, NULL), -- PAU WEB R304
(2025, 44, 3, 9, 2341, 3, 6, 4, NULL, NULL), -- PAU WEB R304
(2025, 44, 3, 2, 2441, 1, 1, 5, NULL, NULL), -- ROY LO R305
(2025, 44, 3, 3, 2441, 1, 1, 5, NULL, NULL), -- ROY LO R305
(2025, 44, 3, 4, 2441, 18, 16, 6, 'blk_kw44_mi_2441_1', NULL), -- HOO SYS WIN E01 Block
(2025, 44, 3, 5, 2441, 18, 16, 6, 'blk_kw44_mi_2441_1', NULL), -- HOO SYS WIN E01 Block
(2025, 44, 3, 6, 2441, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 44, 3, 7, 2441, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 44, 3, 8, 2441, 15, 8, 8, NULL, NULL), -- DUE DBK 103
(2025, 44, 3, 9, 2441, 15, 8, 8, NULL, NULL), -- DUE DBK 103
(2025, 44, 3, 2, 2541, 7, 9, 9, 'blk_kw44_mi_2541_1', NULL), -- SCW ENG 104 Block
(2025, 44, 3, 3, 2541, 7, 9, 9, 'blk_kw44_mi_2541_1', NULL), -- SCW ENG 104 Block
(2025, 44, 3, 4, 2541, 17, 13, 1, NULL, NULL), -- FIS MAT R301
(2025, 44, 3, 5, 2541, 17, 13, 1, NULL, NULL), -- FIS MAT R301
(2025, 44, 3, 6, 2541, 16, 15, 2, 'blk_kw44_mi_2541_2', NULL), -- FAB SAP R302 Block
(2025, 44, 3, 7, 2541, 16, 15, 2, 'blk_kw44_mi_2541_2', NULL), -- FAB SAP R302 Block
(2025, 44, 3, 8, 2541, 9, 2, 3, NULL, NULL), -- BOA BWL R303
(2025, 44, 3, 9, 2541, 9, 2, 3, NULL, NULL), -- BOA BWL R303
(2025, 44, 3, 6, 2442, 12, 9, 4, NULL, NULL), -- DIL ENG R304
(2025, 44, 3, 7, 2442, 12, 9, 4, NULL, NULL), -- DIL ENG R304
(2025, 44, 3, 8, 2442, 21, 17, 5, NULL, NULL), -- KAS SYS NET R305
(2025, 44, 3, 9, 2442, 21, 17, 5, NULL, NULL), -- KAS SYS NET R305
(2025, 44, 3, 2, 2452, 19, 2, 6, 'blk_kw44_mi_2452_1', NULL), -- HAR BWL E01 Block
(2025, 44, 3, 3, 2452, 19, 2, 6, 'blk_kw44_mi_2452_1', NULL), -- HAR BWL E01 Block
(2025, 44, 3, 4, 2452, 8, 18, 7, NULL, NULL), -- MIT SYS LIN 102
(2025, 44, 3, 5, 2452, 8, 18, 7, NULL, NULL), -- MIT SYS LIN 102
(2025, 44, 3, 6, 2452, 22, 13, 8, NULL, NULL), -- PET MAT 103
(2025, 44, 3, 7, 2452, 22, 13, 8, NULL, NULL), -- PET MAT 103
(2025, 44, 3, 8, 2452, 1, 1, 9, NULL, NULL), -- ROY LO 104
(2025, 44, 3, 9, 2452, 1, 1, 9, NULL, NULL), -- ROY LO 104

-- Thursday
(2025, 44, 4, 2, 2341, 5, 9, 1, NULL, NULL), -- GAR ENG R301
(2025, 44, 4, 3, 2341, 5, 9, 1, NULL, NULL), -- GAR ENG R301
(2025, 44, 4, 4, 2341, 13, 13, 2, 'blk_kw44_do_2341_1', NULL), -- BRO MAT R302 Block
(2025, 44, 4, 5, 2341, 13, 13, 2, 'blk_kw44_do_2341_1', NULL), -- BRO MAT R302 Block
(2025, 44, 4, 6, 2341, 20, 7, 3, NULL, NULL), -- KOL DB R303
(2025, 44, 4, 7, 2341, 20, 7, 3, NULL, NULL), -- KOL DB R303
(2025, 44, 4, 8, 2341, 4, 2, 4, NULL, NULL), -- KUE BWL R304
(2025, 44, 4, 9, 2341, 4, 2, 4, NULL, NULL), -- KUE BWL R304
(2025, 44, 4, 2, 2441, 18, 16, 5, NULL, NULL), -- HOO SYS WIN R305
(2025, 44, 4, 3, 2441, 18, 16, 5, NULL, NULL), -- HOO SYS WIN R305
(2025, 44, 4, 4, 2441, 1, 3, 6, NULL, NULL), -- ROY INF E01
(2025, 44, 4, 5, 2441, 1, 3, 6, NULL, NULL), -- ROY INF E01
(2025, 44, 4, 6, 2441, 11, 8, 7, 'blk_kw44_do_2441_1', NULL), -- CRA DBK 102 Block
(2025, 44, 4, 7, 2441, 11, 8, 7, 'blk_kw44_do_2441_1', NULL), -- CRA DBK 102 Block
(2025, 44, 4, 8, 2441, 23, 7, 8, NULL, NULL), -- SCH DB 103
(2025, 44, 4, 9, 2441, 23, 7, 8, NULL, NULL), -- SCH DB 103
(2025, 44, 4, 2, 2541, 2, 2, 9, NULL, NULL), -- BOE BWL 104
(2025, 44, 4, 3, 2541, 2, 2, 9, NULL, NULL), -- BOE BWL 104
(2025, 44, 4, 4, 2541, 24, 8, 1, NULL, NULL), -- VIR DBK R301
(2025, 44, 4, 5, 2541, 24, 8, 1, NULL, NULL), -- VIR DBK R301
(2025, 44, 4, 6, 2541, 13, 13, 2, 'blk_kw44_do_2541_1', NULL), -- BRO MAT R302 Block
(2025, 44, 4, 7, 2541, 13, 13, 2, 'blk_kw44_do_2541_1', NULL), -- BRO MAT R302 Block
(2025, 44, 4, 8, 2541, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 44, 4, 9, 2541, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 44, 4, 6, 2451, 17, 13, 4, NULL, NULL), -- FIS MAT R304
(2025, 44, 4, 7, 2451, 17, 13, 4, NULL, NULL), -- FIS MAT R304
(2025, 44, 4, 8, 2451, 5, 9, 5, NULL, NULL), -- GAR ENG R305
(2025, 44, 4, 9, 2451, 5, 9, 5, NULL, NULL), -- GAR ENG R305
(2025, 44, 4, 2, 2551, 15, 8, 6, NULL, NULL), -- DUE DBK E01
(2025, 44, 4, 3, 2551, 15, 8, 6, NULL, NULL), -- DUE DBK E01
(2025, 44, 4, 4, 2551, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 44, 4, 5, 2551, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 44, 4, 6, 2551, 7, 9, 8, NULL, NULL), -- SCW ENG 103
(2025, 44, 4, 7, 2551, 7, 9, 8, NULL, NULL), -- SCW ENG 103

-- Friday (Ends 12:05 / Period 5)
(2025, 44, 5, 2, 2341, 4, 2, 1, NULL, NULL), -- KUE BWL R301
(2025, 44, 5, 3, 2341, 4, 2, 1, NULL, NULL), -- KUE BWL R301
(2025, 44, 5, 4, 2341, 22, 3, 2, NULL, NULL), -- PET INF R302
(2025, 44, 5, 5, 2341, 22, 3, 2, NULL, NULL), -- PET INF R302
(2025, 44, 5, 2, 2441, 11, 8, 3, NULL, NULL), -- CRA DBK R303
(2025, 44, 5, 3, 2441, 11, 8, 3, NULL, NULL), -- CRA DBK R303
(2025, 44, 5, 4, 2441, 5, 9, 4, NULL, NULL), -- GAR ENG R304
(2025, 44, 5, 5, 2441, 5, 9, 4, NULL, NULL), -- GAR ENG R304
(2025, 44, 5, 2, 2541, 1, 1, 5, 'blk_kw44_fr_2541_1', NULL), -- ROY LO R305 Block
(2025, 44, 5, 3, 2541, 1, 1, 5, 'blk_kw44_fr_2541_1', NULL), -- ROY LO R305 Block
(2025, 44, 5, 4, 2541, 13, 13, 6, NULL, NULL), -- BRO MAT E01
(2025, 44, 5, 5, 2541, 13, 13, 6, NULL, NULL), -- BRO MAT E01
(2025, 44, 5, 2, 2342, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 44, 5, 3, 2342, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 44, 5, 4, 2342, 17, 13, 8, NULL, NULL), -- FIS MAT 103
(2025, 44, 5, 5, 2342, 17, 13, 8, NULL, NULL), -- FIS MAT 103
(2025, 44, 5, 2, 2351, 2, 4, 9, NULL, NULL), -- BOE GdP/Java 104
(2025, 44, 5, 3, 2351, 2, 4, 9, NULL, NULL), -- BOE GdP/Java 104
(2025, 44, 5, 4, 2351, 19, 2, 1, NULL, NULL), -- HAR BWL R301
(2025, 44, 5, 5, 2351, 19, 2, 1, NULL, NULL), -- HAR BWL R301
(2025, 44, 5, 2, 2442, 1, 1, 2, NULL, NULL), -- ROY LO R302
(2025, 44, 5, 3, 2442, 1, 1, 2, NULL, NULL), -- ROY LO R302
(2025, 44, 5, 4, 2442, 21, 17, 3, NULL, NULL), -- KAS SYS NET R303
(2025, 44, 5, 5, 2442, 21, 17, 3, NULL, NULL), -- KAS SYS NET R303
(2025, 44, 5, 2, 2451, 15, 8, 4, NULL, NULL), -- DUE DBK R304
(2025, 44, 5, 3, 2451, 15, 8, 4, NULL, NULL), -- DUE DBK R304
(2025, 44, 5, 4, 2451, 7, 18, 5, NULL, NULL), -- SCW SYS LIN R305
(2025, 44, 5, 5, 2451, 7, 18, 5, NULL, NULL), -- SCW SYS LIN R305
(2025, 44, 5, 2, 2452, 24, 7, 6, NULL, NULL), -- VIR DB E01
(2025, 44, 5, 3, 2452, 24, 7, 6, NULL, NULL), -- VIR DB E01
(2025, 44, 5, 4, 2452, 9, 2, 7, NULL, NULL), -- BOA BWL 102
(2025, 44, 5, 5, 2452, 9, 2, 7, NULL, NULL), -- BOA BWL 102
(2025, 44, 5, 2, 2551, 8, 2, 8, NULL, NULL), -- MIT BWL 103
(2025, 44, 5, 3, 2551, 8, 2, 8, NULL, NULL), -- MIT BWL 103
(2025, 44, 5, 4, 2551, 16, 15, 9, NULL, NULL), -- FAB SAP 104
(2025, 44, 5, 5, 2551, 16, 15, 9, NULL, NULL), -- FAB SAP 104

-- KW 45 / 2025
-- Monday
(2025, 45, 1, 2, 2341, 17, 13, 1, NULL, NULL), -- FIS MAT R301
(2025, 45, 1, 3, 2341, 17, 13, 1, NULL, NULL), -- FIS MAT R301
(2025, 45, 1, 4, 2341, 5, 9, 2, 'blk_kw45_mo_2341_1', NULL), -- GAR ENG R302 Block
(2025, 45, 1, 5, 2341, 5, 9, 2, 'blk_kw45_mo_2341_1', NULL), -- GAR ENG R302 Block
(2025, 45, 1, 6, 2341, 2, 4, 3, NULL, NULL), -- BOE GdP/Java R303
(2025, 45, 1, 7, 2341, 2, 4, 3, NULL, NULL), -- BOE GdP/Java R303
(2025, 45, 1, 8, 2341, 10, 8, 4, NULL, NULL), -- KRO DBK R304
(2025, 45, 1, 9, 2341, 10, 8, 4, NULL, NULL), -- KRO DBK R304
(2025, 45, 1, 2, 2441, 21, 17, 5, 'blk_kw45_mo_2441_1', NULL), -- KAS SYS NET R305 Block
(2025, 45, 1, 3, 2441, 21, 17, 5, 'blk_kw45_mo_2441_1', NULL), -- KAS SYS NET R305 Block
(2025, 45, 1, 4, 2441, 19, 9, 6, NULL, NULL), -- HAR ENG E01
(2025, 45, 1, 5, 2441, 15, 8, 6, NULL, NULL), -- DUE DBK E01
(2025, 45, 1, 6, 2441, 14, 2, 7, 'blk_kw45_mo_2441_2', NULL), -- DAM BWL 102 Block
(2025, 45, 1, 7, 2441, 14, 2, 7, 'blk_kw45_mo_2441_2', NULL), -- DAM BWL 102 Block
(2025, 45, 1, 8, 2441, 18, 16, 8, NULL, NULL), -- HOO SYS WIN 103
(2025, 45, 1, 9, 2441, 18, 16, 8, NULL, NULL), -- HOO SYS WIN 103
(2025, 45, 1, 2, 2541, 9, 2, 9, NULL, NULL), -- BOA BWL 104
(2025, 45, 1, 3, 2541, 9, 2, 9, NULL, NULL), -- BOA BWL 104
(2025, 45, 1, 4, 2541, 24, 8, 1, 'blk_kw45_mo_2541_1', NULL), -- VIR DBK R301 Block
(2025, 45, 1, 5, 2541, 24, 8, 1, 'blk_kw45_mo_2541_1', NULL), -- VIR DBK R301 Block
(2025, 45, 1, 6, 2541, 7, 9, 2, NULL, NULL), -- SCW ENG R302
(2025, 45, 1, 7, 2541, 16, 15, 2, NULL, NULL), -- FAB SAP R302
(2025, 45, 1, 8, 2541, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 1, 9, 2541, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 1, 2, 2342, 1, 3, 4, NULL, NULL), -- ROY INF R304
(2025, 45, 1, 3, 2342, 1, 3, 4, NULL, NULL), -- ROY INF R304
(2025, 45, 1, 4, 2342, 11, 8, 5, NULL, NULL), -- CRA DBK R305
(2025, 45, 1, 5, 2342, 11, 8, 5, NULL, NULL), -- CRA DBK R305
(2025, 45, 1, 6, 2342, 20, 7, 6, 'blk_kw45_mo_2342_1', NULL), -- KOL DB E01 Block
(2025, 45, 1, 7, 2342, 20, 7, 6, 'blk_kw45_mo_2342_1', NULL), -- KOL DB E01 Block
(2025, 45, 1, 8, 2342, 22, 13, 7, NULL, NULL), -- PET MAT 102
(2025, 45, 1, 9, 2342, 22, 13, 7, NULL, NULL), -- PET MAT 102
(2025, 45, 1, 4, 2442, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 45, 1, 5, 2442, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 45, 1, 6, 2442, 12, 9, 9, NULL, NULL), -- DIL ENG 104
(2025, 45, 1, 7, 2442, 12, 9, 9, NULL, NULL), -- DIL ENG 104
(2025, 45, 1, 8, 2442, 15, 2, 1, NULL, NULL), -- DUE BWL R301
(2025, 45, 1, 9, 2442, 15, 2, 1, NULL, NULL), -- DUE BWL R301
(2025, 45, 1, 2, 2351, 8, 2, 3, NULL, NULL), -- MIT BWL R303
(2025, 45, 1, 3, 2351, 8, 2, 3, NULL, NULL), -- MIT BWL R303
(2025, 45, 1, 6, 2451, 23, 7, 4, NULL, NULL), -- SCH DB R304
(2025, 45, 1, 7, 2451, 23, 7, 4, NULL, NULL), -- SCH DB R304
(2025, 45, 1, 2, 2452, 16, 17, 2, NULL, NULL), -- FAB SYS NET R302
(2025, 45, 1, 3, 2452, 16, 17, 2, NULL, NULL), -- FAB SYS NET R302
(2025, 45, 1, 6, 2551, 3, 6, 8, NULL, NULL), -- PAU WEB 103
(2025, 45, 1, 7, 2551, 3, 6, 8, NULL, NULL), -- PAU WEB 103
(2025, 45, 1, 8, 2551, 1, 1, 5, NULL, NULL), -- ROY LO R305
(2025, 45, 1, 9, 2551, 1, 1, 5, NULL, NULL), -- ROY LO R305

-- Tuesday
(2025, 45, 2, 2, 2341, 1, 3, 1, 'blk_kw45_di_2341_1', NULL), -- ROY INF R301 Block
(2025, 45, 2, 3, 2341, 1, 3, 1, 'blk_kw45_di_2341_1', NULL), -- ROY INF R301 Block
(2025, 45, 2, 4, 2341, 20, 7, 2, NULL, NULL), -- KOL DB R302
(2025, 45, 2, 5, 2341, 20, 7, 2, NULL, NULL), -- KOL DB R302
(2025, 45, 2, 6, 2341, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 2, 7, 2341, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 2, 8, 2341, 11, 8, 4, NULL, NULL), -- CRA DBK R304
(2025, 45, 2, 9, 2341, 11, 8, 4, NULL, NULL), -- CRA DBK R304
(2025, 45, 2, 2, 2441, 22, 16, 5, NULL, NULL), -- PET SYS WIN R305
(2025, 45, 2, 3, 2441, 22, 16, 5, NULL, NULL), -- PET SYS WIN R305
(2025, 45, 2, 4, 2441, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 2, 5, 2441, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 2, 6, 2441, 8, 2, 7, NULL, NULL), -- MIT BWL 102
(2025, 45, 2, 7, 2441, 8, 2, 7, NULL, NULL), -- MIT BWL 102
(2025, 45, 2, 8, 2441, 5, 9, 8, NULL, NULL), -- GAR ENG 103
(2025, 45, 2, 9, 2441, 5, 9, 8, NULL, NULL), -- GAR ENG 103
(2025, 45, 2, 2, 2541, 19, 9, 9, NULL, NULL), -- HAR ENG 104
(2025, 45, 2, 3, 2541, 19, 9, 9, NULL, NULL), -- HAR ENG 104
(2025, 45, 2, 4, 2541, 16, 15, 1, 'blk_kw45_di_2541_1', NULL), -- FAB SAP R301 Block
(2025, 45, 2, 5, 2541, 16, 15, 1, 'blk_kw45_di_2541_1', NULL), -- FAB SAP R301 Block
(2025, 45, 2, 6, 2541, 2, 2, 2, NULL, NULL), -- BOE BWL R302
(2025, 45, 2, 7, 2541, 2, 2, 2, NULL, NULL), -- BOE BWL R302
(2025, 45, 2, 8, 2541, 24, 8, 3, NULL, NULL), -- VIR DBK R303
(2025, 45, 2, 9, 2541, 24, 8, 3, NULL, NULL), -- VIR DBK R303
(2025, 45, 2, 2, 2351, 12, 9, 4, 'blk_kw45_di_2351_1', NULL), -- DIL ENG R304 Block
(2025, 45, 2, 3, 2351, 12, 9, 4, 'blk_kw45_di_2351_1', NULL), -- DIL ENG R304 Block
(2025, 45, 2, 4, 2351, 1, 3, 5, NULL, NULL), -- ROY INF R305
(2025, 45, 2, 5, 2351, 1, 3, 5, NULL, NULL), -- ROY INF R305
(2025, 45, 2, 6, 2351, 17, 13, 6, NULL, NULL), -- FIS MAT E01
(2025, 45, 2, 7, 2351, 17, 13, 6, NULL, NULL), -- FIS MAT E01
(2025, 45, 2, 8, 2351, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 45, 2, 9, 2351, 10, 8, 7, NULL, NULL), -- KRO DBK 102
(2025, 45, 2, 2, 2451, 15, 8, 8, NULL, NULL), -- DUE DBK 103
(2025, 45, 2, 3, 2451, 15, 8, 8, NULL, NULL), -- DUE DBK 103
(2025, 45, 2, 6, 2452, 23, 7, 9, NULL, NULL), -- SCH DB 104
(2025, 45, 2, 7, 2452, 23, 7, 9, NULL, NULL), -- SCH DB 104
(2025, 45, 2, 8, 2452, 16, 17, 1, NULL, NULL), -- FAB SYS NET R301
(2025, 45, 2, 9, 2452, 16, 17, 1, NULL, NULL), -- FAB SYS NET R301
(2025, 45, 2, 2, 2551, 21, 15, 2, NULL, NULL), -- KAS SAP R302
(2025, 45, 2, 3, 2551, 21, 15, 2, NULL, NULL), -- KAS SAP R302
(2025, 45, 2, 4, 2551, 10, 8, 3, NULL, NULL), -- KRO DBK R303
(2025, 45, 2, 5, 2551, 10, 8, 3, NULL, NULL), -- KRO DBK R303
(2025, 45, 2, 6, 2551, 1, 1, 4, NULL, NULL), -- ROY LO R304
(2025, 45, 2, 7, 2551, 1, 1, 4, NULL, NULL), -- ROY LO R304
(2025, 45, 2, 8, 2551, 8, 2, 5, 'blk_kw45_di_2551_1', NULL), -- MIT BWL R305 Block
(2025, 45, 2, 9, 2551, 8, 2, 5, 'blk_kw45_di_2551_1', NULL), -- MIT BWL R305 Block

-- Wednesday
(2025, 45, 3, 2, 2341, 10, 8, 1, 'blk_kw45_mi_2341_1', NULL), -- KRO DBK R301 Block
(2025, 45, 3, 3, 2341, 10, 8, 1, 'blk_kw45_mi_2341_1', NULL), -- KRO DBK R301 Block
(2025, 45, 3, 4, 2341, 3, 6, 2, NULL, NULL), -- PAU WEB R302
(2025, 45, 3, 5, 2341, 3, 6, 2, NULL, NULL), -- PAU WEB R302
(2025, 45, 3, 6, 2341, 1, 3, 3, NULL, NULL), -- ROY INF R303
(2025, 45, 3, 7, 2341, 1, 3, 3, NULL, NULL), -- ROY INF R303
(2025, 45, 3, 8, 2341, 17, 13, 4, NULL, NULL), -- FIS MAT R304
(2025, 45, 3, 9, 2341, 17, 13, 4, NULL, NULL), -- FIS MAT R304
(2025, 45, 3, 2, 2441, 11, 8, 5, 'blk_kw45_mi_2441_1', NULL), -- CRA DBK R305 Block
(2025, 45, 3, 3, 2441, 11, 8, 5, 'blk_kw45_mi_2441_1', NULL), -- CRA DBK R305 Block
(2025, 45, 3, 4, 2441, 1, 3, 6, NULL, NULL), -- ROY INF E01
(2025, 45, 3, 5, 2441, 1, 3, 6, NULL, NULL), -- ROY INF E01
(2025, 45, 3, 6, 2441, 19, 9, 7, NULL, NULL), -- HAR ENG 102
(2025, 45, 3, 7, 2441, 19, 9, 7, NULL, NULL), -- HAR ENG 102
(2025, 45, 3, 8, 2441, 8, 2, 8, NULL, NULL), -- MIT BWL 103
(2025, 45, 3, 9, 2441, 8, 2, 8, NULL, NULL), -- MIT BWL 103
(2025, 45, 3, 2, 2541, 1, 1, 9, NULL, NULL), -- ROY LO 104
(2025, 45, 3, 3, 2541, 1, 1, 9, NULL, NULL), -- ROY LO 104
(2025, 45, 3, 4, 2541, 16, 15, 1, NULL, NULL), -- FAB SAP R301
(2025, 45, 3, 5, 2541, 16, 15, 1, NULL, NULL), -- FAB SAP R301
(2025, 45, 3, 6, 2541, 9, 2, 2, 'blk_kw45_mi_2541_1', NULL), -- BOA BWL R302 Block
(2025, 45, 3, 7, 2541, 9, 2, 2, 'blk_kw45_mi_2541_1', NULL), -- BOA BWL R302 Block
(2025, 45, 3, 8, 2541, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 3, 9, 2541, 13, 13, 3, NULL, NULL), -- BRO MAT R303
(2025, 45, 3, 2, 2451, 16, 17, 4, 'blk_kw45_mi_2451_1', NULL), -- FAB SYS NET R304 Block
(2025, 45, 3, 3, 2451, 16, 17, 4, 'blk_kw45_mi_2451_1', NULL), -- FAB SYS NET R304 Block
(2025, 45, 3, 4, 2451, 24, 7, 5, NULL, NULL), -- VIR DB R305
(2025, 45, 3, 5, 2451, 24, 7, 5, NULL, NULL), -- VIR DB R305
(2025, 45, 3, 6, 2451, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 3, 7, 2451, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 3, 8, 2451, 12, 9, 7, NULL, NULL), -- DIL ENG 102
(2025, 45, 3, 9, 2451, 12, 9, 7, NULL, NULL), -- DIL ENG 102
(2025, 45, 3, 2, 2452, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 45, 3, 3, 2452, 1, 1, 8, NULL, NULL), -- ROY LO 103
(2025, 45, 3, 4, 2452, 10, 8, 9, NULL, NULL), -- KRO DBK 104
(2025, 45, 3, 5, 2452, 10, 8, 9, NULL, NULL), -- KRO DBK 104
(2025, 45, 3, 6, 2452, 21, 17, 1, NULL, NULL), -- KAS SYS NET R301
(2025, 45, 3, 7, 2452, 21, 17, 1, NULL, NULL), -- KAS SYS NET R301
(2025, 45, 3, 8, 2452, 14, 2, 2, NULL, NULL), -- DAM BWL R302
(2025, 45, 3, 9, 2452, 14, 2, 2, NULL, NULL), -- DAM BWL R302
(2025, 45, 3, 8, 2551, 22, 13, 3, NULL, NULL), -- PET MAT R303
(2025, 45, 3, 9, 2551, 22, 13, 3, NULL, NULL), -- PET MAT R303

-- Thursday
(2025, 45, 4, 2, 2341, 18, 16, 4, 'blk_kw45_do_2341_1', NULL), -- HOO SYS WIN R304 Block
(2025, 45, 4, 3, 2341, 18, 16, 4, 'blk_kw45_do_2341_1', NULL), -- HOO SYS WIN R304 Block
(2025, 45, 4, 4, 2341, 4, 2, 5, NULL, NULL), -- KUE BWL R305
(2025, 45, 4, 5, 2341, 4, 2, 5, NULL, NULL), -- KUE BWL R305
(2025, 45, 4, 6, 2341, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 4, 7, 2341, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 4, 8, 2341, 2, 4, 7, NULL, NULL), -- BOE GdP/Java 102
(2025, 45, 4, 9, 2341, 2, 4, 7, NULL, NULL), -- BOE GdP/Java 102
(2025, 45, 4, 2, 2441, 23, 7, 8, 'blk_kw45_do_2441_1', NULL), -- SCH DB 103 Block
(2025, 45, 4, 3, 2441, 23, 7, 8, 'blk_kw45_do_2441_1', NULL), -- SCH DB 103 Block
(2025, 45, 4, 4, 2441, 15, 8, 9, NULL, NULL), -- DUE DBK 104
(2025, 45, 4, 5, 2441, 15, 8, 9, NULL, NULL), -- DUE DBK 104
(2025, 45, 4, 6, 2441, 1, 3, 1, NULL, NULL), -- ROY INF R301
(2025, 45, 4, 7, 2441, 1, 3, 1, NULL, NULL), -- ROY INF R301
(2025, 45, 4, 8, 2441, 21, 17, 2, NULL, NULL), -- KAS SYS NET R302
(2025, 45, 4, 9, 2441, 21, 17, 2, NULL, NULL), -- KAS SYS NET R302
(2025, 45, 4, 2, 2541, 13, 13, 3, 'blk_kw45_do_2541_1', NULL), -- BRO MAT R303 Block
(2025, 45, 4, 3, 2541, 13, 13, 3, 'blk_kw45_do_2541_1', NULL), -- BRO MAT R303 Block
(2025, 45, 4, 4, 2541, 19, 9, 4, NULL, NULL), -- HAR ENG R304
(2025, 45, 4, 5, 2541, 19, 9, 4, NULL, NULL), -- HAR ENG R304
(2025, 45, 4, 6, 2541, 1, 1, 5, NULL, NULL), -- ROY LO R305
(2025, 45, 4, 7, 2541, 1, 1, 5, NULL, NULL), -- ROY LO R305
(2025, 45, 4, 8, 2541, 16, 15, 6, NULL, NULL), -- FAB SAP E01
(2025, 45, 4, 9, 2541, 16, 15, 6, NULL, NULL), -- FAB SAP E01
(2025, 45, 4, 2, 2351, 19, 2, 7, 'blk_kw45_do_2351_1', NULL), -- HAR BWL 102 Block
(2025, 45, 4, 3, 2351, 19, 2, 7, 'blk_kw45_do_2351_1', NULL), -- HAR BWL 102 Block
(2025, 45, 4, 4, 2351, 13, 13, 8, NULL, NULL), -- BRO MAT 103
(2025, 45, 4, 5, 2351, 13, 13, 8, NULL, NULL), -- BRO MAT 103
(2025, 45, 4, 6, 2351, 3, 6, 9, 'blk_kw45_do_2351_2', NULL), -- PAU WEB 104 Block
(2025, 45, 4, 7, 2351, 3, 6, 9, 'blk_kw45_do_2351_2', NULL), -- PAU WEB 104 Block
(2025, 45, 4, 8, 2351, 20, 7, 1, NULL, NULL), -- KOL DB R301
(2025, 45, 4, 9, 2351, 20, 7, 1, NULL, NULL), -- KOL DB R301
(2025, 45, 4, 6, 2442, 1, 1, 2, NULL, NULL), -- ROY LO R302
(2025, 45, 4, 7, 2442, 1, 1, 2, NULL, NULL), -- ROY LO R302
(2025, 45, 4, 8, 2442, 23, 7, 3, NULL, NULL), -- SCH DB R303
(2025, 45, 4, 9, 2442, 23, 7, 3, NULL, NULL), -- SCH DB R303
(2025, 45, 4, 2, 2451, 8, 18, 4, NULL, NULL), -- MIT SYS LIN R304
(2025, 45, 4, 3, 2451, 8, 18, 4, NULL, NULL), -- MIT SYS LIN R304
(2025, 45, 4, 4, 2451, 24, 7, 5, NULL, NULL), -- VIR DB R305
(2025, 45, 4, 5, 2451, 24, 7, 5, NULL, NULL), -- VIR DB R305
(2025, 45, 4, 2, 2452, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 4, 3, 2452, 1, 1, 6, NULL, NULL), -- ROY LO E01
(2025, 45, 4, 4, 2452, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 45, 4, 5, 2452, 14, 2, 7, NULL, NULL), -- DAM BWL 102
(2025, 45, 4, 2, 2551, 22, 13, 8, NULL, NULL), -- PET MAT 103
(2025, 45, 4, 3, 2551, 22, 13, 8, NULL, NULL), -- PET MAT 103
(2025, 45, 4, 4, 2551, 15, 8, 9, NULL, NULL), -- DUE DBK 104
(2025, 45, 4, 5, 2551, 15, 8, 9, NULL, NULL), -- DUE DBK 104
(2025, 45, 4, 6, 2551, 21, 15, 1, NULL, NULL), -- KAS SAP R301
(2025, 45, 4, 7, 2551, 21, 15, 1, NULL, NULL), -- KAS SAP R301
(2025, 45, 4, 8, 2551, 3, 6, 2, NULL, NULL), -- PAU WEB R302
(2025, 45, 4, 9, 2551, 3, 6, 2, NULL, NULL), -- PAU WEB R302

-- Friday (Ends 12:05 / Period 5)
(2025, 45, 5, 2, 2341, 11, 8, 1, NULL, NULL), -- CRA DBK R301
(2025, 45, 5, 3, 2341, 11, 8, 1, NULL, NULL), -- CRA DBK R301
(2025, 45, 5, 4, 2341, 18, 16, 2, NULL, NULL), -- HOO SYS WIN R302
(2025, 45, 5, 5, 2341, 18, 16, 2, NULL, NULL), -- HOO SYS WIN R302
(2025, 45, 5, 2, 2441, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 45, 5, 3, 2441, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 45, 5, 4, 2441, 15, 8, 4, NULL, NULL), -- DUE DBK R304
(2025, 45, 5, 5, 2441, 15, 8, 4, NULL, NULL), -- DUE DBK R304
(2025, 45, 5, 2, 2541, 19, 9, 5, NULL, NULL), -- HAR ENG R305
(2025, 45, 5, 3, 2541, 19, 9, 5, NULL, NULL), -- HAR ENG R305
(2025, 45, 5, 4, 2541, 2, 2, 6, NULL, NULL), -- BOE BWL E01
(2025, 45, 5, 5, 2541, 2, 2, 6, NULL, NULL), -- BOE BWL E01
(2025, 45, 5, 2, 2342, 22, 13, 7, NULL, NULL), -- PET MAT 102
(2025, 45, 5, 3, 2342, 22, 13, 7, NULL, NULL), -- PET MAT 102
(2025, 45, 5, 4, 2342, 1, 3, 8, NULL, NULL), -- ROY INF 103
(2025, 45, 5, 5, 2342, 1, 3, 8, NULL, NULL), -- ROY INF 103
(2025, 45, 5, 2, 2351, 17, 13, 9, NULL, NULL), -- FIS MAT 104
(2025, 45, 5, 3, 2351, 17, 13, 9, NULL, NULL), -- FIS MAT 104
(2025, 45, 5, 4, 2351, 5, 9, 1, NULL, NULL), -- GAR ENG R301
(2025, 45, 5, 5, 2351, 5, 9, 1, NULL, NULL), -- GAR ENG R301
(2025, 45, 5, 2, 2442, 15, 2, 2, NULL, NULL), -- DUE BWL R302
(2025, 45, 5, 3, 2442, 15, 2, 2, NULL, NULL), -- DUE BWL R302
(2025, 45, 5, 4, 2442, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 45, 5, 5, 2442, 1, 1, 3, NULL, NULL), -- ROY LO R303
(2025, 45, 5, 2, 2451, 2, 8, 4, NULL, NULL), -- BOE DBK R304
(2025, 45, 5, 3, 2451, 2, 8, 4, NULL, NULL), -- BOE DBK R304
(2025, 45, 5, 4, 2451, 12, 9, 5, NULL, NULL), -- DIL ENG R305
(2025, 45, 5, 5, 2451, 12, 9, 5, NULL, NULL), -- DIL ENG R305
(2025, 45, 5, 2, 2452, 22, 13, 6, NULL, NULL), -- PET MAT E01
(2025, 45, 5, 3, 2452, 22, 13, 6, NULL, NULL), -- PET MAT E01
(2025, 45, 5, 4, 2452, 8, 18, 7, NULL, NULL), -- MIT SYS LIN 102
(2025, 45, 5, 5, 2452, 8, 18, 7, NULL, NULL), -- MIT SYS LIN 102
(2025, 45, 5, 2, 2551, 14, 2, 8, NULL, NULL), -- DAM BWL 103
(2025, 45, 5, 3, 2551, 14, 2, 8, NULL, NULL), -- DAM BWL 103
(2025, 45, 5, 4, 2551, 7, 9, 9, NULL, NULL), -- SCW ENG 104
(2025, 45, 5, 5, 2551, 7, 9, 9, NULL, NULL) -- SCW ENG 104
ON DUPLICATE KEY UPDATE entry_id=entry_id; -- Do nothing if entry exists

-- Generated Substitution Entries (Adding more examples)
INSERT INTO `substitutions` (`date`, `period_number`, `class_id`, `substitution_type`, `original_subject_id`, `new_teacher_id`, `new_subject_id`, `new_room_id`, `comment`) VALUES
-- KW 44
('2025-10-27', 4, 2341, 'Entfall', 8, NULL, NULL, NULL, 'Lehrer krank'),
('2025-10-27', 5, 2341, 'Entfall', 8, NULL, NULL, NULL, 'Lehrer krank'),
('2025-10-28', 6, 2342, 'Raumänderung', 13, NULL, NULL, 4, 'Raum 303 belegt'),
('2025-10-28', 7, 2342, 'Raumänderung', 13, NULL, NULL, 4, 'Raum 303 belegt'),
('2025-10-29', 2, 2351, 'Vertretung', 3, 3, 6, 1, 'Vertretung für ROY'),
('2025-10-29', 3, 2351, 'Vertretung', 3, 3, 6, 1, 'Vertretung für ROY'),
('2025-10-29', 6, 2451, 'Vertretung', 8, 11, 8, 6, 'BOE verhindert'), -- CRA macht DBK für BOE
('2025-10-29', 7, 2451, 'Vertretung', 8, 11, 8, 6, 'BOE verhindert'), -- CRA macht DBK für BOE
('2025-10-30', 8, 2441, 'Raumänderung', 16, NULL, NULL, 2, NULL), -- PET SYS WIN in R302
('2025-10-30', 9, 2441, 'Raumänderung', 16, NULL, NULL, 2, NULL), -- PET SYS WIN in R302
('2025-10-31', 8, 2452, 'Sonderevent', NULL, NULL, NULL, 6, 'Halloween-Feier Aula'),
('2025-10-31', 9, 2452, 'Sonderevent', NULL, NULL, NULL, 6, 'Halloween-Feier Aula'),
('2025-10-31', 4, 2342, 'Entfall', 4, NULL, NULL, NULL, 'HOO auf Fortbildung'), -- HOO GdP/Java entfällt
('2025-10-31', 5, 2342, 'Entfall', 4, NULL, NULL, NULL, 'HOO auf Fortbildung'), -- HOO GdP/Java entfällt
('2025-10-30', 2, 2551, 'Vertretung', 8, 24, 8, 6, 'DUE krank'), -- VIR macht DBK
('2025-10-30', 3, 2551, 'Vertretung', 8, 24, 8, 6, 'DUE krank'), -- VIR macht DBK

-- KW 45
('2025-11-03', 6, 2341, 'Entfall', 4, NULL, NULL, NULL, 'Fortbildung'),
('2025-11-03', 7, 2341, 'Entfall', 4, NULL, NULL, NULL, 'Fortbildung'),
('2025-11-04', 8, 2442, 'Raumänderung', 16, NULL, NULL, 9, NULL),
('2025-11-04', 9, 2442, 'Raumänderung', 16, NULL, NULL, 9, NULL),
('2025-11-05', 4, 2541, 'Vertretung', 13, 22, 13, 1, 'FIS krank, PET übernimmt MAT'),
('2025-11-05', 5, 2541, 'Vertretung', 13, 22, 13, 1, 'FIS krank, PET übernimmt MAT'),
('2025-11-06', 8, 2441, 'Vertretung', 2, 19, 2, 1, 'KUE verhindert'), -- HAR macht BWL
('2025-11-06', 9, 2441, 'Vertretung', 2, 19, 2, 1, 'KUE verhindert'), -- HAR macht BWL
('2025-11-07', 4, 2341, 'Entfall', 3, NULL, NULL, NULL, 'Feiertagsvorbereitung'), -- PET INF entfällt
('2025-11-07', 5, 2341, 'Entfall', 3, NULL, NULL, NULL, 'Feiertagsvorbereitung'), -- PET INF entfällt
('2025-11-05', 2, 2452, 'Raumänderung', 2, NULL, NULL, 1, 'Raum E01 anderweitig genutzt'), -- HAR BWL nach R301
('2025-11-05', 3, 2452, 'Raumänderung', 2, NULL, NULL, 1, 'Raum E01 anderweitig genutzt'), -- HAR BWL nach R301
('2025-11-03', 4, 2342, 'Vertretung', 8, 10, 8, 5, 'CRA krank, KRO übernimmt'), -- KRO für CRA (DBK)
('2025-11-03', 5, 2342, 'Vertretung', 8, 10, 8, 5, 'CRA krank, KRO übernimmt'), -- KRO für CRA (DBK)
('2025-11-04', 2, 2341, 'Entfall', 3, NULL, NULL, NULL, 'ROY krank'), -- ROY INF entfällt
('2025-11-04', 3, 2341, 'Entfall', 3, NULL, NULL, NULL, 'ROY krank'), -- ROY INF entfällt
('2025-11-06', 6, 2541, 'Sonderevent', 9, NULL, 1, 5, 'Gastvortrag BWL/LO'), -- Gastvortrag statt ENG
('2025-11-06', 7, 2541, 'Sonderevent', 15, NULL, 1, 5, 'Gastvortrag BWL/LO'), -- Gastvortrag statt SAP
('2025-11-06', 8, 2551, 'Raumänderung', 6, NULL, NULL, 1, 'R302 hat technischen Defekt'), -- PAU WEB in R301
('2025-11-06', 9, 2551, 'Raumänderung', 6, NULL, NULL, 1, 'R302 hat technischen Defekt') -- PAU WEB in R301
ON DUPLICATE KEY UPDATE substitution_id=substitution_id; -- Do nothing if substitution exists

-- Publish Status (Publish both weeks for both groups)
INSERT INTO `timetable_publish_status` (`year`, `calendar_week`, `target_group`, `publisher_user_id`) VALUES
(2025, 44, 'student', 1), -- Published by Admin (User ID 1)
(2025, 44, 'teacher', 1),
(2025, 45, 'student', 1),
(2025, 45, 'teacher', 1)
ON DUPLICATE KEY UPDATE published_at = NOW(), publisher_user_id = VALUES(publisher_user_id);

-- Seed-Daten für Tabelle 'community_posts'
-- 18 Einträge (2 pro Schüler), 50% 'approved', 50% 'pending'

-- Admin-Benutzer (ID 1) wird als Moderator für 'approved' Posts gesetzt.

-- Anna (ID 29)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(29, 'Jacke gefunden!', 'Hab eine blaue Regenjacke im Raum 301 gefunden. Liegt jetzt vorn am Pult.', 'approved', NOW() - INTERVAL '10' DAY, 1, NOW() - INTERVAL '10' DAY),
(29, 'Lerngruppe WEB?', 'Suche Leute für eine Lerngruppe Webentwicklung (Klasse 2341). Wer hat Lust, einmal die Woche die Themen zu wiederholen?', 'pending', NOW() - INTERVAL '1' DAY, NULL, NULL);

-- Ben (ID 30)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(30, 'Biete Nachhilfe in Java', 'Hi, ich biete Nachhilfe in Java (GdP) an. Bei Interesse bitte an meine E-Mail schreiben (siehe Profil).', 'approved', NOW() - INTERVAL '9' DAY, 1, NOW() - INTERVAL '9' DAY),
(30, 'Projektidee: Pausen-App', 'Hat jemand Lust, als Abschlussprojekt eine App zu bauen, die anzeigt, wo die Freunde gerade Pause machen? Brauche noch 2 Leute.', 'pending', NOW() - INTERVAL '2' HOUR, NULL, NULL);

-- Clara (ID 31)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(31, 'Neue Schach-AG!', 'Wir gründen eine Schach-AG! Treffen immer mittwochs in der 7. Stunde in Raum E01. Anfänger willkommen!', 'approved', NOW() - INTERVAL '8' DAY, 1, NOW() - INTERVAL '8' DAY),
(31, 'Verkaufe Buch: Datenbanken (6. Auflage)', 'Verkaufe mein altes DB-Buch, kaum benutzt. 20€. Bei Interesse E-Mail an mich.', 'pending', NOW() - INTERVAL '3' DAY, NULL, NULL);

-- David (ID 32)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(32, 'Mitfahrgelegenheit Mannheim Hbf', 'Fahre jeden Tag von Mannheim Hbf (ca. 7:15) zur Schule. Habe noch 2 Plätze frei. Bei Interesse bitte per E-Mail melden.', 'approved', NOW() - INTERVAL '7' DAY, 1, NOW() - INTERVAL '7' DAY),
(32, 'Fußballgruppe nach der Schule?', 'Wer hat Lust, donnerstags nach der Schule auf dem Bolzplatz zu kicken? Suchen noch Mitspieler.', 'pending', NOW() - INTERVAL '1' DAY, NULL, NULL);

-- Elena (ID 33)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(33, 'Schlüsselbund verloren!', 'Habe meinen Schlüsselbund verloren. Blauer Anhänger. Bitte im Sekretariat abgeben, falls gefunden!', 'approved', NOW() - INTERVAL '6' DAY, 1, NOW() - INTERVAL '6' DAY),
(33, 'Grillfest am Freitag?', 'Hi, wollen wir (Klasse 2442) am Freitag nach der 5. Stunde im Hof grillen? Müssten wir beim Hausmeister anmelden. Wer wäre dabei?', 'pending', NOW() - INTERVAL '5' HOUR, NULL, NULL);

-- Finn (ID 34)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(34, 'Verkaufe 24-Zoll Monitor', 'Verkaufe meinen alten Dell 24-Zoll Monitor. Funktioniert einwandfrei. 50€ VB. Bilder per Mail.', 'approved', NOW() - INTERVAL '5' DAY, 1, NOW() - INTERVAL '5' DAY),
(34, 'Umfrage für Abschlussprojekt (WICHTIG)', 'Hey Leute, ich brauche für mein Abschlussprojekt eure Meinung zum Thema "Cloud-Gaming". Dauert nur 5 Minuten! \n\n [Link zur Umfrage] \n\n Danke!', 'pending', NOW() - INTERVAL '6' HOUR, NULL, NULL);

-- Greta (ID 35)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(35, 'Lerngruppe BWL (Klasse 2452)', 'Suchen noch 1-2 Leute für unsere BWL-Lerngruppe. Treffen immer dienstags 6./7. Stunde (FU-Zeit).', 'approved', NOW() - INTERVAL '4' DAY, 1, NOW() - INTERVAL '4' DAY),
(35, 'Brille gefunden (Raum 102)', 'Schwarze Ray-Ban Brille in Raum 102 gefunden. Liegt im Sekretariat.', 'pending', NOW() - INTERVAL '1' DAY, NULL, NULL);

-- Hugo (ID 36)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(36, 'Fahrtgemeinschaft aus Heidelberg?', 'Fährt jemand aus Heidelberg (Südstadt) und hat noch Platz? Würde mich beteiligen! Bitte per E-Mail melden.', 'approved', NOW() - INTERVAL '3' DAY, 1, NOW() - INTERVAL '3' DAY),
(36, 'Verkaufe Skript SYS-WIN', 'Verkaufe mein altes, markiertes SYS-WIN Skript vom letzten Jahrgang. 5€.', 'pending', NOW() - INTERVAL '2' DAY, NULL, NULL);

-- Helga (ID 37)
INSERT INTO `community_posts` (user_id, title, content, status, created_at, moderator_id, moderated_at) 
VALUES 
(37, 'Werkstudentenjob (IT-Support) zu vergeben', 'Meine Firma (kleines Systemhaus in HD) sucht einen Werkstudenten (m/w/d) für 1st-Level-Support. Bei Interesse bitte E-Mail an mich.', 'approved', NOW() - INTERVAL '2' DAY, 1, NOW() - INTERVAL '2' DAY),
(37, 'Suche WG-Zimmer in Neckarstadt', 'Hi, ich (Helga, 2541) suche ab Dezember ein WG-Zimmer in Mannheim, am liebsten Neckarstadt. Falls ihr was hört...', 'pending', NOW() - INTERVAL '4' HOUR, NULL, NULL);
