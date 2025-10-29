<?php
// app/Repositories/PlanRepository.php
namespace App\Repositories;

use PDO;
use Exception;
use DateTime;
use DateTimeZone; // Added explicit use
use PDOException; // Added for specific exception handling
use App\Repositories\TeacherAbsenceRepository; // NEU: Import

class PlanRepository
{
    private PDO $pdo;
    private TeacherAbsenceRepository $absenceRepo; // NEU: Property

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->absenceRepo = new TeacherAbsenceRepository($pdo); // NEU: Instanziieren
    }

    /**
     * Hilfsfunktion, um Start- und Enddatum einer Kalenderwoche zu ermitteln.
     * @param int $year ISO Year
     * @param int $week ISO Week
     * @return array ['Y-m-d', 'Y-m-d']
     */
    private function getWeekDateRange(int $year, int $week): array
    {
        // Use DateTime for ISO week date calculations
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1); // Set to Monday of the week
        $startDate = $dto->format('Y-m-d');
        $dto->setISODate($year, $week, 5); // Set to Friday of the week
        $endDate = $dto->format('Y-m-d');
        return [$startDate, $endDate];
    }

    // --- Methoden für den öffentlichen Dashboard-Zugriff (Schüler/Lehrer) ---

    /**
     * Holt den regulären Stundenplan für eine Klasse, ABER NUR WENN veröffentlicht.
     * @param int $classId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getPublishedTimetableForClass(int $classId, int $year, int $calendarWeek): array
    {
        // Prüfe zuerst, ob die Woche veröffentlicht ist
        if (!$this->isWeekPublishedFor('student', $year, $calendarWeek)) {
            return []; // Leeres Array, wenn nicht veröffentlicht
        }
        // Use the AsPlaner method as the underlying data is the same
        return $this->getTimetableForClassAsPlaner($classId, $year, $calendarWeek);
    }

    /**
     * Holt den regulären Stundenplan für einen Lehrer, ABER NUR WENN veröffentlicht.
     * @param int $teacherId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getPublishedTimetableForTeacher(int $teacherId, int $year, int $calendarWeek): array
    {
        // Prüfe zuerst, ob die Woche veröffentlicht ist
        if (!$this->isWeekPublishedFor('teacher', $year, $calendarWeek)) {
            return []; // Leeres Array, wenn nicht veröffentlicht
        }
        // Use the AsPlaner method as the underlying data is the same
        return $this->getTimetableForTeacherAsPlaner($teacherId, $year, $calendarWeek);
    }

    /**
     * Holt alle Vertretungen für eine Klasse in einer Woche, ABER NUR WENN veröffentlicht.
     * @param int $classId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getPublishedSubstitutionsForClassWeek(int $classId, int $year, int $calendarWeek): array
    {
        if (!$this->isWeekPublishedFor('student', $year, $calendarWeek)) {
            return [];
        }
        // Use the AsPlaner method as the underlying data is the same
        return $this->getSubstitutionsForClassWeekAsPlaner($classId, $year, $calendarWeek);
    }

    /**
     * Holt alle Vertretungen für einen Lehrer in einer Woche, ABER NUR WENN veröffentlicht.
     * @param int $teacherId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getPublishedSubstitutionsForTeacherWeek(int $teacherId, int $year, int $calendarWeek): array
    {
         if (!$this->isWeekPublishedFor('teacher', $year, $calendarWeek)) {
             return [];
         }
        // Use the AsPlaner method as the underlying data is the same
        return $this->getSubstitutionsForTeacherWeekAsPlaner($teacherId, $year, $calendarWeek);
    }

    // --- Methoden für den Planer-Zugriff ---

    /**
     * Holt den regulären Stundenplan für eine Klasse (für Planer/Admin).
     * @param int $classId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getTimetableForClassAsPlaner(int $classId, int $year, int $calendarWeek): array
    {
        $sql = "SELECT te.*, s.subject_shortcut, s.subject_name, t.teacher_shortcut, r.room_name, c.class_name
                FROM timetable_entries te
                LEFT JOIN subjects s ON te.subject_id = s.subject_id
                LEFT JOIN teachers t ON te.teacher_id = t.teacher_id
                LEFT JOIN rooms r ON te.room_id = r.room_id
                JOIN classes c ON te.class_id = c.class_id
                WHERE te.class_id = :class_id
                    AND te.year = :year
                    AND te.calendar_week = :calendar_week
                ORDER BY te.day_of_week ASC, te.period_number ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':class_id' => $classId, ':year' => $year, ':calendar_week' => $calendarWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Holt den regulären Stundenplan für einen Lehrer (für Planer/Admin).
     * @param int $teacherId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getTimetableForTeacherAsPlaner(int $teacherId, int $year, int $calendarWeek): array
    {
        $sql = "SELECT te.*, s.subject_shortcut, s.subject_name, c.class_name, r.room_name, t.teacher_shortcut
                FROM timetable_entries te
                LEFT JOIN subjects s ON te.subject_id = s.subject_id
                JOIN classes c ON te.class_id = c.class_id
                LEFT JOIN rooms r ON te.room_id = r.room_id
                JOIN teachers t ON te.teacher_id = t.teacher_id
                WHERE te.teacher_id = :teacher_id
                    AND te.year = :year
                    AND te.calendar_week = :calendar_week
                ORDER BY te.day_of_week ASC, te.period_number ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_id' => $teacherId, ':year' => $year, ':calendar_week' => $calendarWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Holt alle Vertretungen für eine Klasse in einer Woche (für Planer/Admin).
     * @param int $classId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getSubstitutionsForClassWeekAsPlaner(int $classId, int $year, int $calendarWeek): array
    {
        return $this->getSubstitutionsForWeekInternal($year, $calendarWeek, $classId, null);
    }

    /**
     * Holt alle Vertretungen für einen Lehrer in einer Woche (für Planer/Admin).
     * @param int $teacherId
     * @param int $year
     * @param int $calendarWeek
     * @return array
     */
    public function getSubstitutionsForTeacherWeekAsPlaner(int $teacherId, int $year, int $calendarWeek): array
    {
        return $this->getSubstitutionsForWeekInternal($year, $calendarWeek, null, $teacherId);
    }

    /**
     * Interne Methode zum Abrufen von Vertretungen für eine Woche, gefiltert nach Klasse oder Lehrer.
     * @param int $year
     * @param int $calendarWeek
     * @param int|null $classId
     * @param int|null $teacherId
     * @return array
     */
    private function getSubstitutionsForWeekInternal(int $year, int $calendarWeek, ?int $classId, ?int $teacherId): array
    {
        [$startDate, $endDate] = $this->getWeekDateRange($year, $calendarWeek);

        // Calculate day_of_week (1=Mon, 5=Fri) using SQL DAYOFWEEK (Sunday=1, Monday=2...). Exclude weekends.
        $sql = "SELECT
                            s.*,
                            DAYOFWEEK(s.date) as day_of_week_iso, /* MySQL Sunday=1, keep for reference */
                            CASE DAYOFWEEK(s.date) WHEN 1 THEN NULL WHEN 7 THEN NULL ELSE DAYOFWEEK(s.date) - 1 END as day_of_week, /* Calculate day_of_week (1=Mon, 5=Fri) */
                            orig_s.subject_shortcut as original_subject_shortcut,
                            new_t.teacher_shortcut as new_teacher_shortcut,
                            new_s.subject_shortcut as new_subject_shortcut,
                            new_r.room_name as new_room_name,
                            c.class_name
                        FROM substitutions s
                        JOIN classes c ON s.class_id = c.class_id
                        LEFT JOIN subjects orig_s ON s.original_subject_id = orig_s.subject_id
                        LEFT JOIN teachers new_t ON s.new_teacher_id = new_t.teacher_id
                        LEFT JOIN subjects new_s ON s.new_subject_id = new_s.subject_id
                        LEFT JOIN rooms new_r ON s.new_room_id = new_r.room_id
                        WHERE s.date BETWEEN :start_date AND :end_date";

        $params = [':start_date' => $startDate, ':end_date' => $endDate];

        if ($classId !== null) {
            $sql .= " AND s.class_id = :class_id";
            $params[':class_id'] = $classId;
        } elseif ($teacherId !== null) {
            // Check if the teacher is the new teacher OR was the original teacher of the replaced lesson
            $sql .= " AND (s.new_teacher_id = :teacher_id OR EXISTS (
                            SELECT 1 FROM timetable_entries te
                            WHERE te.class_id = s.class_id
                                AND te.year = :year
                                AND te.calendar_week = :calendar_week
                                AND te.day_of_week = (CASE DAYOFWEEK(s.date) WHEN 1 THEN NULL WHEN 7 THEN NULL ELSE DAYOFWEEK(s.date) - 1 END)
                                AND te.period_number = s.period_number
                                AND te.teacher_id = :teacher_id
                        ))";
            $params[':teacher_id'] = $teacherId;
            $params[':year'] = $year; // Add year and week for subquery
            $params[':calendar_week'] = $calendarWeek;
        }

        $sql .= " ORDER BY s.date ASC, s.period_number ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Methoden zum Verwalten des Veröffentlichungsstatus ---

    /**
     * Veröffentlicht den Plan für eine Woche und Zielgruppe.
     * @param int $year
     * @param int $calendarWeek
     * @param string $targetGroup 'student' or 'teacher'
     * @param int $userId ID des veröffentlichenden Benutzers
     * @return bool Erfolg
     */
    public function publishWeek(int $year, int $calendarWeek, string $targetGroup, int $userId): bool
    {
        $sql = "INSERT INTO timetable_publish_status (year, calendar_week, target_group, published_at, publisher_user_id)
                VALUES (:year, :calendar_week, :target_group, NOW(), :user_id)
                ON DUPLICATE KEY UPDATE published_at = NOW(), publisher_user_id = VALUES(publisher_user_id)"; // Update timestamp and publisher
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':target_group' => $targetGroup,
            ':user_id' => $userId
        ]);
    }

    /**
     * Nimmt die Veröffentlichung für eine Woche und Zielgruppe zurück.
     * @param int $year
     * @param int $calendarWeek
     * @param string $targetGroup 'student' or 'teacher'
     * @return bool Erfolg
     */
    public function unpublishWeek(int $year, int $calendarWeek, string $targetGroup): bool
    {
        $sql = "DELETE FROM timetable_publish_status
                WHERE year = :year AND calendar_week = :calendar_week AND target_group = :target_group";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':target_group' => $targetGroup
        ]);
    }

    /**
     * Holt den Veröffentlichungsstatus für eine Woche.
     * @param int $year
     * @param int $calendarWeek
     * @return array ['student' => bool, 'teacher' => bool]
     */
    public function getPublishStatus(int $year, int $calendarWeek): array
    {
        $sql = "SELECT target_group FROM timetable_publish_status
                WHERE year = :year AND calendar_week = :calendar_week";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':year' => $year, ':calendar_week' => $calendarWeek]);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        // Ensure both keys always exist
        return ['student' => in_array('student', $results), 'teacher' => in_array('teacher', $results)];
    }


    /**
     * Interne Hilfsfunktion zum Prüfen des Status für eine Zielgruppe.
     * @param string $targetGroup 'student' or 'teacher'
     * @param int $year
     * @param int $calendarWeek
     * @return bool True if published, False otherwise.
     */
    public function isWeekPublishedFor(string $targetGroup, int $year, int $calendarWeek): bool
    {
        $sql = "SELECT 1 FROM timetable_publish_status
                WHERE year = :year AND calendar_week = :calendar_week AND target_group = :target_group LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':target_group' => $targetGroup
        ]);
        return $stmt->fetchColumn() !== false;
    }


    // --- Methoden zum Bearbeiten von Daten ---

    /**
     * Löscht einen einzelnen Stundenplaneintrag.
     * @param int $entryId
     * @return bool Erfolg
     */
    public function deleteEntry(int $entryId): bool
    {
        $sql = "DELETE FROM timetable_entries WHERE entry_id = :entry_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':entry_id' => $entryId]);
    }

    /**
     * Löscht alle Einträge, die zu einem Block gehören.
     * @param string $blockId
     * @return bool Erfolg
     */
    public function deleteEntryBlock(string $blockId): bool
    {
        $sql = "DELETE FROM timetable_entries WHERE block_id = :block_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':block_id' => $blockId]);
    }

    /**
     * Erstellt oder aktualisiert einen Stundenplaneintrag (oder Block).
     * @param array $data Daten aus dem Formular/API-Call.
     * @return array Informationen über den erstellten/aktualisierten Eintrag (z.B. block_id).
     * @throws Exception
     */
    public function createOrUpdateEntry(array $data): array
    {
        // Validate required fields
        $required = ['year', 'calendar_week', 'day_of_week', 'class_id', 'teacher_id', 'subject_id', 'room_id'];
        foreach ($required as $field) {
            // Check if required fields potentially holding ID '0' are missing or truly empty strings
            // IDs should usually start from 1. If '0' is not valid, add $data[$field] === 0 check.
             // Allow class_id '0' for teacher-mode entries
            if ($field === 'class_id' && ($data[$field] === 0 || $data[$field] === '0')) {
                // Skip check, '0' is allowed for class_id
            }
            elseif (!isset($data[$field]) || $data[$field] === '') {
                throw new Exception("Fehlende Daten: Feld '{$field}' ist erforderlich und darf nicht leer sein.");
            }
        }

        // Sanitize comment
        $comment = isset($data['comment']) ? trim($data['comment']) : null;
        if ($comment === '') {
             $comment = null; // Store NULL instead of empty string
        }

        $startPeriod = (int)($data['start_period_number'] ?? $data['period_number'] ?? 0);
        $endPeriod = (int)($data['end_period_number'] ?? $data['period_number'] ?? 0);
        
        // Add start/end period back to data array for checkConflicts
        $data['start_period_number'] = $startPeriod;
        $data['end_period_number'] = $endPeriod;


        if ($startPeriod <= 0 || $endPeriod <= 0 || $startPeriod > $endPeriod) {
            throw new Exception("Ungültiger Stundenbereich (Start/Ende > 0 und Start <= Ende erforderlich).");
        }
        
        // *** NEW: Check Conflicts BEFORE transaction ***
        $excludeEntryId = !empty($data['entry_id']) ? (int)$data['entry_id'] : null;
        $excludeBlockId = !empty($data['block_id']) ? (string)$data['block_id'] : null;
        
        // Note: checkConflicts will now throw an Exception if conflicts are found
        $this->checkConflicts($data, $excludeEntryId, $excludeBlockId);
        // *** END NEW CONFLICT CHECK ***


        // --- Transaction ---
        $this->pdo->beginTransaction();
        try {
            // Delete existing entries for this exact slot(s) first
            // If updating, delete the specific old entry/block instead of just the timeslot
             if (!empty($data['entry_id']) && filter_var($data['entry_id'], FILTER_VALIDATE_INT)) {
                 $deleteSql = "DELETE FROM timetable_entries WHERE entry_id = :entry_id";
                 $deleteParams = [':entry_id' => $data['entry_id']];
            } elseif (!empty($data['block_id'])) {
                 $deleteSql = "DELETE FROM timetable_entries WHERE block_id = :block_id";
                 $deleteParams = [':block_id' => $data['block_id']];
            } else {
                 // Deleting by timeslot (when creating a new entry over an empty slot - shouldn't delete anything, but safe)
                 $deleteSql = "DELETE FROM timetable_entries
                                 WHERE year = :year
                                   AND calendar_week = :calendar_week
                                   AND day_of_week = :day_of_week
                                   AND class_id = :class_id
                                   AND period_number >= :start_period AND period_number <= :end_period";
                 $deleteParams = [
                     ':year' => $data['year'],
                     ':calendar_week' => $data['calendar_week'],
                     ':day_of_week' => $data['day_of_week'],
                     ':class_id' => $data['class_id'], // Ensure deletion is class-specific
                     ':start_period' => $startPeriod,
                     ':end_period' => $endPeriod
                 ];
            }

             $deleteStmt = $this->pdo->prepare($deleteSql);
             $deleteStmt->execute($deleteParams);


            // Generate block_id only if it's a multi-period entry
            $blockId = ($startPeriod !== $endPeriod) ? uniqid('block_', true) : null;

            $insertSql = "INSERT INTO timetable_entries (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment)
                           VALUES (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";

            $insertStmt = $this->pdo->prepare($insertSql);

            $insertedIds = []; // To potentially return IDs if needed
            for ($period = $startPeriod; $period <= $endPeriod; $period++) {
                $params = [
                    ':year' => $data['year'],
                    ':calendar_week' => $data['calendar_week'],
                    ':day_of_week' => $data['day_of_week'],
                    ':period_number' => $period,
                    ':class_id' => $data['class_id'], // Can be '0' for teacher mode
                    ':teacher_id' => $data['teacher_id'],
                    ':subject_id' => $data['subject_id'],
                    ':room_id' => $data['room_id'],
                    ':block_id' => $blockId,
                    ':comment' => $comment
                ];
                if (!$insertStmt->execute($params)) {
                    // Get detailed error info
                    $errorInfo = $insertStmt->errorInfo();
                    throw new PDOException("Fehler beim Einfügen von Stunde {$period}. SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                 $insertedIds[] = $this->pdo->lastInsertId(); // Store last insert ID
            }

            $this->pdo->commit();

            // Return relevant info
             return [
                 'block_id' => $blockId,
                 'entry_ids' => $insertedIds, // Return array of created entry IDs
                 'periods' => range($startPeriod, $endPeriod)
             ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            // Log the detailed error
            error_log("PlanRepository::createOrUpdateEntry failed: " . $e->getMessage());
            // Rethrow a more generic error for the user, potentially including specifics if safe
            throw new Exception("Fehler beim Speichern des Stundenplaneintrags: " . $e->getMessage());
        }
    }


    /**
     * Erstellt oder aktualisiert eine Vertretung.
     * @param array $data Daten aus dem Formular/API-Call.
     * @return array Die Daten der erstellten/aktualisierten Vertretung inkl. ID.
     * @throws Exception
     */
    public function createOrUpdateSubstitution(array $data): array
    {
        // Validate required fields
        if (empty($data['date']) || empty($data['period_number']) || !isset($data['class_id']) || empty($data['substitution_type'])) {
            throw new Exception("Datum, Stunde, Klasse und Vertretungstyp sind Pflichtfelder.");
        }
        // Basic date validation
        if (DateTime::createFromFormat('Y-m-d', $data['date']) === false) {
            throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.");
        }
        
        // NEU: Konfliktprüfung für den NEUEN Lehrer (falls gesetzt)
        if (!empty($data['new_teacher_id'])) {
            // 1. Prüfe auf Doppelbuchung des Lehrers (regulärer Unterricht)
            $dateObj = new DateTime($data['date']);
            $conflictData = [
                'year' => (int)$dateObj->format('o'),
                'calendar_week' => (int)$dateObj->format('W'),
                'day_of_week' => (int)$dateObj->format('N'),
                'start_period_number' => $data['period_number'],
                'end_period_number' => $data['period_number'],
                'teacher_id' => $data['new_teacher_id'],
                'room_id' => null, // Wir prüfen nur den Lehrer
                'class_id' => $data['class_id'] // Die Klasse, in die er soll
            ];
            // Wir müssen die aktuelle Vertretungs-ID (falls vorhanden) von der Prüfung ausschließen
            // $excludeSubId = !empty($data['substitution_id']) ? (int)$data['substitution_id'] : null;
            // HINWEIS: checkConflicts prüft aktuell nur timetable_entries. Wir müssen Vertretungen separat prüfen.
            
            // checkConflicts wirft eine Exception, wenn ein Konflikt in timetable_entries gefunden wird
            try {
                $this->checkConflicts($conflictData, null, null);
            } catch (Exception $e) {
                // Passe die Fehlermeldung an
                if (str_contains($e->getMessage(), 'LEHRER-KONFLIKT')) {
                    throw new Exception("KONFLIKT: Dieser Lehrer hält bereits regulären Unterricht in einer anderen Klasse.", 409, $e);
                }
                throw $e; // Wirf andere Konflikte (z.B. Klasse) erneut
            }

            // 2. Prüfe auf Doppelbuchung (Vertretungen)
            $sqlCheckSub = "SELECT 1 FROM substitutions 
                            WHERE new_teacher_id = :teacher_id 
                              AND date = :date AND period_number = :period
                              AND substitution_id != :exclude_id
                            LIMIT 1";
            $stmtCheckSub = $this->pdo->prepare($sqlCheckSub);
            $stmtCheckSub->execute([
                ':teacher_id' => $data['new_teacher_id'],
                ':date' => $data['date'],
                ':period' => $data['period_number'],
                ':exclude_id' => $data['substitution_id'] ?? 0
            ]);
            if ($stmtCheckSub->fetchColumn()) {
                throw new Exception("KONFLIKT: Dieser Lehrer hält bereits eine andere Vertretung in dieser Stunde.", 409);
            }

            // 3. NEU: Prüfe auf Abwesenheit des NEUEN Lehrers
            $absence = $this->absenceRepo->checkAbsence($data['new_teacher_id'], $data['date']);
            if ($absence) {
                throw new Exception("KONFLIKT: Der Vertretungslehrer (ID {$data['new_teacher_id']}) ist an diesem Tag als '{$absence['reason']}' gemeldet.", 409);
            }
        }
        // --- ENDE NEUE KONFLIKTPRÜFUNG ---


        // Set fields to null if they are empty strings or '0' for foreign keys
        $nullableFields = ['original_subject_id', 'new_teacher_id', 'new_subject_id', 'new_room_id', 'comment'];
        foreach ($nullableFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                // Treat empty string or '0' as NULL for optional foreign keys, keep comment as empty string if intended
                if ($value === '' || ($value === '0' && $field !== 'comment')) {
                    $data[$field] = null;
                } else {
                    $data[$field] = $value; // Keep trimmed value otherwise
                }
            } else {
                $data[$field] = null; // Ensure key exists and is null if not provided
            }
        }


        if (!empty($data['substitution_id']) && filter_var($data['substitution_id'], FILTER_VALIDATE_INT)) {
            // Update existing substitution
            $sql = "UPDATE substitutions SET
                                date = :date,
                                period_number = :period_number,
                                class_id = :class_id,
                                substitution_type = :substitution_type,
                                original_subject_id = :original_subject_id,
                                new_teacher_id = :new_teacher_id,
                                new_subject_id = :new_subject_id,
                                new_room_id = :new_room_id,
                                comment = :comment
                            WHERE substitution_id = :substitution_id";
            $currentId = (int)$data['substitution_id'];
        } else {
            // Insert new substitution
            $sql = "INSERT INTO substitutions (date, period_number, class_id, substitution_type, original_subject_id, new_teacher_id, new_subject_id, new_room_id, comment)
                    VALUES (:date, :period_number, :class_id, :substitution_type, :original_subject_id, :new_teacher_id, :new_subject_id, :new_room_id, :comment)";
            $currentId = null; // Will get ID after insert
        }

        $stmt = $this->pdo->prepare($sql);

        $params = [
            ':date' => $data['date'],
            ':period_number' => $data['period_number'],
            ':class_id' => $data['class_id'], // Can be '0' if coming from teacher mode
            ':substitution_type' => $data['substitution_type'],
            ':original_subject_id' => $data['original_subject_id'],
            ':new_teacher_id' => $data['new_teacher_id'],
            ':new_subject_id' => $data['new_subject_id'],
            ':new_room_id' => $data['new_room_id'],
            ':comment' => $data['comment'], // Use sanitized value (can be null or trimmed string)
        ];

        if ($currentId !== null) {
            $params[':substitution_id'] = $currentId;
        }

        if (!$stmt->execute($params)) {
            $errorInfo = $stmt->errorInfo();
            error_log("Substitution save failed: SQLSTATE[{$errorInfo[0]}] {$errorInfo[2]}");
            throw new Exception("Fehler beim Speichern der Vertretung.");
        }

         if ($currentId === null) {
             $currentId = (int)$this->pdo->lastInsertId();
         }

         // Fetch the saved data to return it (including potentially looked up names/shortcuts)
         $savedData = $this->getSubstitutionById($currentId);
         if (!$savedData) {
             // Fallback if fetch fails, return input data with ID
             $data['substitution_id'] = $currentId;
             // Add calculated day_of_week for consistency
             try {
                 $dateObj = new DateTime($data['date']);
                 $dayOfWeek = $dateObj->format('N'); // 1 (Mon) - 7 (Sun)
                 $data['day_of_week'] = ($dayOfWeek >= 1 && $dayOfWeek <= 5) ? $dayOfWeek : null;
                 $data['day_of_week_iso'] = $dateObj->format('N'); // Keep ISO day if needed elsewhere
             } catch (Exception $e) {
                 $data['day_of_week'] = null;
                 $data['day_of_week_iso'] = null;
             }
             return $data;
         }
         return $savedData;
    }

     /**
      * Holt eine einzelne Vertretung anhand ihrer ID mit zusätzlichen Details.
      * @param int $substitutionId
      * @return array|false
      */
     public function getSubstitutionById(int $substitutionId): array|false
     {
         $sql = "SELECT
                                    s.*,
                                    DAYOFWEEK(s.date) as day_of_week_iso, /* MySQL Sunday=1 */
                                    CASE DAYOFWEEK(s.date) WHEN 1 THEN NULL WHEN 7 THEN NULL ELSE DAYOFWEEK(s.date) - 1 END as day_of_week, /* Calculate day_of_week (1=Mon, 5=Fri) */
                                    orig_s.subject_shortcut as original_subject_shortcut,
                                    new_t.teacher_shortcut as new_teacher_shortcut,
                                    new_s.subject_shortcut as new_subject_shortcut,
                                    new_r.room_name as new_room_name,
                                    c.class_name
                                FROM substitutions s
                                JOIN classes c ON s.class_id = c.class_id
                                LEFT JOIN subjects orig_s ON s.original_subject_id = orig_s.subject_id
                                LEFT JOIN teachers new_t ON s.new_teacher_id = new_t.teacher_id
                                LEFT JOIN subjects new_s ON s.new_subject_id = new_s.subject_id
                                LEFT JOIN rooms new_r ON s.new_room_id = new_r.room_id
                                WHERE s.substitution_id = :id";
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute([':id' => $substitutionId]);
         return $stmt->fetch(PDO::FETCH_ASSOC);
     }


    /**
     * Löscht eine Vertretung.
     * @param int $substitutionId
     * @return bool Erfolg
     */
    public function deleteSubstitution(int $substitutionId): bool
    {
        $sql = "DELETE FROM substitutions WHERE substitution_id = :substitution_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':substitution_id' => $substitutionId]);
    }

    /**
     * Checks for conflicts (teacher or room double-booking) for a given timeslot.
     * @param array $data Contains year, calendar_week, day_of_week, start_period_number, end_period_number, teacher_id, room_id, class_id
     * @param int|null $excludeEntryId Entry ID to exclude (during updates)
     * @param string|null $excludeBlockId Block ID to exclude (during updates)
     * @return array List of conflict messages.
     * @throws Exception If conflicts are found (to be caught by API handler).
     */
    public function checkConflicts(array $data, ?int $excludeEntryId = null, ?string $excludeBlockId = null): array
    {
        $conflicts = [];
        $baseSql = "SELECT te.*, c.class_name, t.teacher_shortcut, r.room_name
                                FROM timetable_entries te
                                LEFT JOIN classes c ON te.class_id = c.class_id
                                LEFT JOIN teachers t ON te.teacher_id = t.teacher_id
                                LEFT JOIN rooms r ON te.room_id = r.room_id
                                WHERE te.year = :year
                                  AND te.calendar_week = :calendar_week
                                  AND te.day_of_week = :day_of_week
                                  AND te.period_number >= :start_period
                                  AND te.period_number <= :end_period";

        $params = [
            ':year' => $data['year'],
            ':calendar_week' => $data['calendar_week'],
            ':day_of_week' => $data['day_of_week'],
            ':start_period' => $data['start_period_number'],
            ':end_period' => $data['end_period_number'],
        ];

        // Add exclusion conditions if updating
        $exclusionSql = "";
        if ($excludeEntryId !== null) {
            $exclusionSql = " AND te.entry_id != :exclude_entry_id";
            $params[':exclude_entry_id'] = $excludeEntryId;
        } elseif ($excludeBlockId !== null) {
            $exclusionSql = " AND te.block_id != :exclude_block_id";
            $params[':exclude_block_id'] = $excludeBlockId;
        }
        
        // NEU: Hole das Datum für die Abwesenheitsprüfung
        $dateForAbsenceCheck = '';
        try {
            $dto = new DateTime();
            $dto->setISODate($data['year'], $data['calendar_week'], $data['day_of_week']);
            $dateForAbsenceCheck = $dto->format('Y-m-d');
        } catch (Exception $e) {
            throw new Exception("Interner Fehler: Datum für Konfliktprüfung konnte nicht berechnet werden.");
        }


        // 1. Check Teacher Conflict (booked in another class at the same time)
        if (!empty($data['teacher_id'])) {
            // 1a. NEU: Auf Abwesenheit prüfen
            $absence = $this->absenceRepo->checkAbsence($data['teacher_id'], $dateForAbsenceCheck);
            if ($absence) {
                $conflicts[] = "LEHRER-KONFLIKT: Lehrer (ID {$data['teacher_id']}) ist an diesem Tag als '{$absence['reason']}' gemeldet.";
            }

            // 1b. Auf Doppelbuchung (Stundenplan) prüfen
            $teacherSql = $baseSql . " AND te.teacher_id = :teacher_id" . $exclusionSql;
            $teacherParams = $params + [':teacher_id' => $data['teacher_id']];
            
            // Remove exclusion params if they are not in the query
            if ($excludeEntryId === null) unset($teacherParams[':exclude_entry_id']);
            if ($excludeBlockId === null) unset($teacherParams[':exclude_block_id']);

            $stmtTeacher = $this->pdo->prepare($teacherSql);
            $stmtTeacher->execute($teacherParams);
            $existingTeacherEntry = $stmtTeacher->fetch(PDO::FETCH_ASSOC);

            if ($existingTeacherEntry) {
                $shortcut = $existingTeacherEntry['teacher_shortcut'] ?: $data['teacher_id'];
                // Verständlichere Meldung:
                $conflicts[] = "LEHRER-KONFLIKT: '{$shortcut}' ist bereits in Klasse {$existingTeacherEntry['class_name']} ({$existingTeacherEntry['room_name']}) eingeteilt.";
            }
        }

        // 2. Check Room Conflict (booked by another class at the same time)
         if (!empty($data['room_id'])) {
            $roomSql = $baseSql . " AND te.room_id = :room_id" . $exclusionSql;
            $roomParams = $params + [':room_id' => $data['room_id']];
            
            if ($excludeEntryId === null) unset($roomParams[':exclude_entry_id']);
            if ($excludeBlockId === null) unset($roomParams[':exclude_block_id']);

            $stmtRoom = $this->pdo->prepare($roomSql);
            $stmtRoom->execute($roomParams);
            $existingRoomEntry = $stmtRoom->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRoomEntry) {
                $name = $existingRoomEntry['room_name'] ?: $data['room_id'];
                // Verständlichere Meldung:
                $conflicts[] = "RAUM-KONFLIKT: '{$name}' ist bereits von Klasse {$existingRoomEntry['class_name']} (Lehrer: {$existingRoomEntry['teacher_shortcut']}) belegt.";
            }
         }
        
        // 3. Check Class Conflict (class booked for another lesson at the same time)
         // Nur prüfen, wenn es nicht der Lehrermodus ist (dort ist class_id 0 oder null)
         if (!empty($data['class_id']) && $data['class_id'] !== '0' && $data['class_id'] !== 0) {
            $classSql = $baseSql . " AND te.class_id = :class_id" . $exclusionSql;
            $classParams = $params + [':class_id' => $data['class_id']];
            if ($excludeEntryId === null) unset($classParams[':exclude_entry_id']);
            if ($excludeBlockId === null) unset($classParams[':exclude_block_id']);

            $stmtClass = $this->pdo->prepare($classSql);
            $stmtClass->execute($classParams);
            $existingClassEntry = $stmtClass->fetch(PDO::FETCH_ASSOC);

            if ($existingClassEntry) {
                 // *** GEÄNDERTE MELDUNG (Benutzerwunsch) ***
                 $conflicts[] = "KONFLIKT (Slot belegt): Die Klasse {$existingClassEntry['class_name']} hat in diesem Zeitraum bereits Unterricht.";
            }
         }

        // Throw exception if conflicts found (to be caught by handleApiRequest in saveEntry)
        if (!empty($conflicts)) {
            // Wirft die erste (oder kombinierte) Meldung als Fehler
            throw new Exception(implode("\n", $conflicts));
        }

        return $conflicts; // Return empty array if no conflicts
    }

    /**
     * NEU: Kopiert Stundenplandaten von einer Woche in eine andere für eine Klasse oder einen Lehrer.
     * @param int $sourceYear
     * @param int $sourceWeek
     * @param int $targetYear
     * @param int $targetWeek
     * @param int|null $classId
     * @param int|null $teacherId
     * @return int Anzahl der kopierten Einträge.
     * @throws Exception
     */
    public function copyWeekData(int $sourceYear, int $sourceWeek, int $targetYear, int $targetWeek, ?int $classId, ?int $teacherId): int
    {
        if ($classId === null && $teacherId === null) {
            throw new Exception("Es muss entweder eine Klasse oder ein Lehrer zum Kopieren ausgewählt werden.");
        }
        if ($sourceYear === $targetYear && $sourceWeek === $targetWeek) {
            throw new Exception("Quell- und Zielwoche dürfen nicht identisch sein.");
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Zieldaten löschen
            $deleteSql = "DELETE FROM timetable_entries 
                            WHERE year = :target_year AND calendar_week = :target_week";
            $deleteParams = [
                ':target_year' => $targetYear,
                ':target_week' => $targetWeek
            ];
            
            $whereField = "";
            if ($classId !== null) {
                $deleteSql .= " AND class_id = :entity_id";
                $whereField = "class_id";
                $deleteParams[':entity_id'] = $classId;
            } else {
                $deleteSql .= " AND teacher_id = :entity_id";
                $whereField = "teacher_id";
                $deleteParams[':entity_id'] = $teacherId;
            }
            
            $this->pdo->prepare($deleteSql)->execute($deleteParams);

            // 2. Quelldaten holen
            $selectSql = "SELECT * FROM timetable_entries
                            WHERE year = :source_year AND calendar_week = :source_week AND $whereField = :entity_id";
            
            $stmtSelect = $this->pdo->prepare($selectSql);
            $stmtSelect->execute([
                ':source_year' => $sourceYear,
                ':source_week' => $sourceWeek,
                ':entity_id' => $classId ?? $teacherId
            ]);
            $sourceEntries = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

            if (empty($sourceEntries)) {
                $this->pdo->rollBack(); // Rückgängig machen, da keine Daten zum Kopieren vorhanden waren
                return 0; // 0 Einträge kopiert
            }

            // 3. Neue Einträge vorbereiten und einfügen
            $insertSql = "INSERT INTO timetable_entries 
                            (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment) 
                            VALUES 
                            (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";
            
            $stmtInsert = $this->pdo->prepare($insertSql);
            
            $copiedCount = 0;
            $blockIdMap = []; // Mappt alte block_ids auf neue

            foreach ($sourceEntries as $entry) {
                // Generiere neue block_id, falls vorhanden, und behalte sie für die Woche bei
                $newBlockId = null;
                if ($entry['block_id']) {
                    if (!isset($blockIdMap[$entry['block_id']])) {
                        $blockIdMap[$entry['block_id']] = uniqid('block_', true);
                    }
                    $newBlockId = $blockIdMap[$entry['block_id']];
                }

                $success = $stmtInsert->execute([
                    ':year' => $targetYear, // Zieljahr
                    ':calendar_week' => $targetWeek, // Zielwoche
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entry['class_id'],
                    ':teacher_id' => $entry['teacher_id'],
                    ':subject_id' => $entry['subject_id'],
                    ':room_id' => $entry['room_id'],
                    ':block_id' => $newBlockId, // Neue Block-ID
                    ':comment' => $entry['comment']
                ]);
                if ($success) {
                    $copiedCount++;
                }
            }

            $this->pdo->commit();
            return $copiedCount;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PlanRepository::copyWeekData failed: " . $e->getMessage());
            throw new Exception("Fehler beim Kopieren der Wochendaten: " . $e->getMessage());
        }
    }

    // --- NEUE METHODEN FÜR VORLAGEN ---

    /**
     * Erstellt eine neue Stundenplan-Vorlage aus vorhandenen Einträgen.
     * @param string $name Name der Vorlage.
     * @param string|null $description Beschreibung der Vorlage.
     * @param array $sourceEntries Die Stundenplaneinträge (z.B. aus getTimetableFor...AsPlaner).
     * @return int Die ID der neu erstellten Vorlage.
     * @throws Exception
     */
    public function createTemplate(string $name, ?string $description, array $sourceEntries): int
    {
        if (empty($name)) {
            throw new Exception("Vorlagenname darf nicht leer sein.");
        }
        if (empty($sourceEntries)) {
            throw new Exception("Vorlage muss mindestens einen Eintrag enthalten.");
        }

        // Prüfen, ob der Name bereits existiert
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_templates WHERE name = :name");
        $stmtCheck->execute([':name' => $name]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("Eine Vorlage mit dem Namen '{$name}' existiert bereits.", 409); // Use 409 for conflict
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Vorlage erstellen
            $sqlTemplate = "INSERT INTO timetable_templates (name, description) VALUES (:name, :description)";
            $stmtTemplate = $this->pdo->prepare($sqlTemplate);
            $stmtTemplate->execute([':name' => $name, ':description' => $description]);
            $templateId = (int)$this->pdo->lastInsertId();

            // 2. Einträge für die Vorlage erstellen
            $sqlEntry = "INSERT INTO timetable_template_entries
                            (template_id, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_ref, comment)
                            VALUES
                            (:template_id, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_ref, :comment)";
            $stmtEntry = $this->pdo->prepare($sqlEntry);

            $blockRefMap = []; // Mappt originale block_ids zu neuen block_refs für diese Vorlage

            foreach ($sourceEntries as $entry) {
                $blockRef = null;
                if ($entry['block_id']) {
                    if (!isset($blockRefMap[$entry['block_id']])) {
                        $blockRefMap[$entry['block_id']] = uniqid('tpl_blk_', true); // Eindeutige Referenz für Blöcke in DIESER Vorlage
                    }
                    $blockRef = $blockRefMap[$entry['block_id']];
                }

                $stmtEntry->execute([
                    ':template_id' => $templateId,
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entry['class_id'], // Speichert die Klasse des ursprünglichen Eintrags
                    ':teacher_id' => $entry['teacher_id'],
                    ':subject_id' => $entry['subject_id'],
                    ':room_id' => $entry['room_id'],
                    ':block_ref' => $blockRef,
                    ':comment' => $entry['comment']
                ]);
            }

            $this->pdo->commit();
            return $templateId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PlanRepository::createTemplate failed: " . $e->getMessage());
            // Rethrow specific conflict error
             if (str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() == 409) {
                 throw new Exception("Eine Vorlage mit dem Namen '{$name}' existiert bereits.", 409);
             }
            throw new Exception("Fehler beim Erstellen der Vorlage: " . $e->getMessage());
        }
    }

    /**
     * Ruft alle verfügbaren Vorlagen ab.
     * @return array Array von Vorlagen [['template_id', 'name', 'description'], ...].
     */
    public function getTemplates(): array
    {
        $stmt = $this->pdo->query("SELECT template_id, name, description FROM timetable_templates ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Wendet eine Vorlage auf eine spezifische Woche für eine Klasse oder einen Lehrer an.
     * Überschreibt vorhandene Einträge für diese Entität in der Zielwoche.
     * @param int $templateId ID der anzuwendenden Vorlage.
     * @param int $targetYear Zieljahr.
     * @param int $targetWeek Zielwoche.
     * @param int|null $targetClassId ID der Zielklasse (wenn Anwenden auf Klasse).
     * @param int|null $targetTeacherId ID des Ziellehrers (wenn Anwenden auf Lehrer).
     * @return int Anzahl der angewendeten Einträge.
     * @throws Exception
     */
    public function applyTemplateToWeek(int $templateId, int $targetYear, int $targetWeek, ?int $targetClassId, ?int $targetTeacherId): int
    {
        if ($targetClassId === null && $targetTeacherId === null) {
            throw new Exception("Es muss entweder eine Klasse oder ein Lehrer als Ziel angegeben werden.");
        }

        // 1. Vorlageneinträge abrufen
        $stmtFetch = $this->pdo->prepare("SELECT * FROM timetable_template_entries WHERE template_id = :template_id ORDER BY day_of_week, period_number");
        $stmtFetch->execute([':template_id' => $templateId]);
        $templateEntries = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

        if (empty($templateEntries)) {
            // Vorlage ist leer oder existiert nicht
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            // 2. Bestehende Einträge für Zielwoche/-entität löschen
            $deleteSql = "DELETE FROM timetable_entries
                            WHERE year = :target_year AND calendar_week = :target_week";
            $deleteParams = [':target_year' => $targetYear, ':target_week' => $targetWeek];

            if ($targetClassId !== null) {
                $deleteSql .= " AND class_id = :entity_id";
                $deleteParams[':entity_id'] = $targetClassId;
                $entityIdField = 'class_id';
                $entityIdValue = $targetClassId;
            } else {
                $deleteSql .= " AND teacher_id = :entity_id";
                $deleteParams[':entity_id'] = $targetTeacherId;
                $entityIdField = 'teacher_id';
                $entityIdValue = $targetTeacherId;
            }
            $this->pdo->prepare($deleteSql)->execute($deleteParams);

            // 3. Neue Einträge basierend auf Vorlage einfügen
            $insertSql = "INSERT INTO timetable_entries
                            (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment)
                            VALUES
                            (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";
            $stmtInsert = $this->pdo->prepare($insertSql);

            $appliedCount = 0;
            $blockIdMap = []; // Mappt template block_ref zu neuer, eindeutiger block_id für die Zielwoche

            foreach ($templateEntries as $entry) {
                $newBlockId = null;
                if ($entry['block_ref']) {
                    if (!isset($blockIdMap[$entry['block_ref']])) {
                        $blockIdMap[$entry['block_ref']] = uniqid('block_', true); // Generiert eindeutige ID für die Zielwoche
                    }
                    $newBlockId = $blockIdMap[$entry['block_ref']];
                }

                // Bestimme die korrekte class_id für den neuen Eintrag
                // Wenn wir auf einen Lehrer anwenden, MUSS die class_id aus der Vorlage kommen.
                // Wenn wir auf eine Klasse anwenden, MUSS die class_id die Zielklasse sein.
                $entryClassId = ($entityIdField === 'class_id') ? $entityIdValue : $entry['class_id'];
                // Stelle sicher, dass eine class_id vorhanden ist, wenn auf Lehrer angewendet wird
                if ($entityIdField === 'teacher_id' && (empty($entryClassId) || $entryClassId == 0)) {
                   // Überspringe diesen Eintrag oder wirf einen Fehler, da Lehrer ohne Klasse nicht geplant werden kann
                   error_log("Template apply skipped: Teacher template entry missing class_id. TemplateEntryID: " . $entry['template_entry_id']);
                   continue; // Überspringe diesen Eintrag
                }


                $success = $stmtInsert->execute([
                    ':year' => $targetYear,
                    ':calendar_week' => $targetWeek,
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entryClassId, // Angepasste Klassen-ID
                    ':teacher_id' => $entry['teacher_id'],
                    ':subject_id' => $entry['subject_id'],
                    ':room_id' => $entry['room_id'],
                    ':block_id' => $newBlockId, // Neue Block-ID für die Zielwoche
                    ':comment' => $entry['comment']
                ]);
                if ($success) {
                    $appliedCount++;
                } else {
                    // Optional: Fehler loggen, falls ein Eintrag fehlschlägt
                    error_log("Failed to apply template entry: " . print_r($stmtInsert->errorInfo(), true));
                }
            }

            $this->pdo->commit();
            return $appliedCount;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PlanRepository::applyTemplateToWeek failed: " . $e->getMessage());
            throw new Exception("Fehler beim Anwenden der Vorlage: " . $e->getMessage());
        }
    }

    /**
     * Löscht eine Vorlage und alle zugehörigen Einträge.
     * @param int $templateId ID der zu löschenden Vorlage.
     * @return bool Erfolg.
     */
    public function deleteTemplate(int $templateId): bool
    {
        // Durch ON DELETE CASCADE in der DB werden die Einträge automatisch mitgelöscht
        $sql = "DELETE FROM timetable_templates WHERE template_id = :template_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':template_id' => $templateId]);
    }

    // --- ENDE NEUE METHODEN FÜR VORLAGEN ---

    /**
     * Lädt die Details einer Vorlage (Stammdaten und Einträge).
     *
     * @param int $templateId
     * @return array
     * @throws Exception
     */
    public function loadTemplateDetails(int $templateId): array
    {
        // 1. Vorlagen-Stammdaten abrufen
        $stmtTemplate = $this->pdo->prepare("SELECT * FROM timetable_templates WHERE template_id = :id");
        $stmtTemplate->execute([':id' => $templateId]);
        $templateInfo = $stmtTemplate->fetch(PDO::FETCH_ASSOC);

        if (!$templateInfo) {
            throw new Exception("Vorlage nicht gefunden.");
        }

        // 2. Zugehörige Einträge abrufen
        // WICHTIG: Wir holen die Roh-IDs, da der Planer-Editor die Stammdaten bereits hat
        $stmtEntries = $this->pdo->prepare("SELECT * FROM timetable_template_entries WHERE template_id = :id ORDER BY day_of_week, period_number");
        $stmtEntries->execute([':id' => $templateId]);
        $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);

        return [
            'template' => $templateInfo,
            'entries' => $entries
        ];
    }

    /**
     * Speichert eine Vorlage (neu oder Update) basierend auf den Editor-Daten.
     *
     * @param array $data Daten mit ['name', 'description', 'template_id' (optional), 'entries' (array)]
     * @return array Die gespeicherten Vorlagen-Stammdaten (inkl. ID)
     * @throws Exception
     */
    public function saveTemplateDetails(array $data): array
    {
        $templateId = $data['template_id'] ?? null;
        $name = trim($data['name']);
        $description = trim($data['description'] ?? '') ?: null;
        $entries = $data['entries'] ?? [];

        if (empty($name)) {
            throw new Exception("Vorlagenname darf nicht leer sein.");
        }

        $this->pdo->beginTransaction();
        try {
            // Prüfen, ob der Name (außerhalb dieser ID) bereits existiert
            $sqlCheckName = "SELECT template_id FROM timetable_templates WHERE name = :name AND (:id IS NULL OR template_id != :id)";
            $stmtCheckName = $this->pdo->prepare($sqlCheckName);
            $stmtCheckName->execute([':name' => $name, ':id' => $templateId]);
            if ($stmtCheckName->fetch()) {
                throw new Exception("Eine andere Vorlage mit diesem Namen existiert bereits.", 409);
            }

            if ($templateId) {
                // Update existing template
                $sqlTemplate = "UPDATE timetable_templates SET name = :name, description = :description WHERE template_id = :id";
                $stmtTemplate = $this->pdo->prepare($sqlTemplate);
                $stmtTemplate->execute([':name' => $name, ':description' => $description, ':id' => $templateId]);
            } else {
                // Create new template
                $sqlTemplate = "INSERT INTO timetable_templates (name, description) VALUES (:name, :description)";
                $stmtTemplate = $this->pdo->prepare($sqlTemplate);
                $stmtTemplate->execute([':name' => $name, ':description' => $description]);
                $templateId = (int)$this->pdo->lastInsertId();
            }

            // Einträge neu schreiben (Delete + Insert)
            $stmtDelete = $this->pdo->prepare("DELETE FROM timetable_template_entries WHERE template_id = :id");
            $stmtDelete->execute([':id' => $templateId]);

            if (!empty($entries)) {
                $sqlEntry = "INSERT INTO timetable_template_entries
                                (template_id, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_ref, comment)
                                VALUES
                                (:template_id, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_ref, :comment)";
                $stmtEntry = $this->pdo->prepare($sqlEntry);

                foreach ($entries as $entry) {
                    $stmtEntry->execute([
                        ':template_id' => $templateId,
                        ':day_of_week' => $entry['day_of_week'],
                        ':period_number' => $entry['period_number'],
                        ':class_id' => $entry['class_id'],
                        ':teacher_id' => $entry['teacher_id'],
                        ':subject_id' => $entry['subject_id'],
                        ':room_id' => $entry['room_id'],
                        ':block_ref' => $entry['block_ref'] ?: null,
                        ':comment' => $entry['comment'] ?: null
                    ]);
                }
            }

            $this->pdo->commit();

            return [
                'template_id' => $templateId,
                'name' => $name,
                'description' => $description
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            // Loggen Sie den detaillierten Fehler
            error_log("PlanRepository::saveTemplateDetails failed: " . $e->getMessage());
            // Werfen Sie den Fehler erneut, damit der Controller ihn fangen kann
            // Behalten Sie den Konflikt-Code 409 bei
            $errorCode = $e->getCode() == 409 ? 409 : 500;
            throw new Exception("Fehler beim Speichern der Vorlage: " . $e->getMessage(), $errorCode);
        }
    }


    public function findTeacherLocation(int $teacherId, string $date, int $year, int $calendarWeek, int $dayOfWeek, int $periodNumber): array
    {
        // 1. Prüfen, ob der Lehrer als NEUER Lehrer in einer Vertretung eingeteilt ist
        $sqlSubAsNew = "SELECT s.*, c.class_name, ns.subject_shortcut as new_subject_shortcut, nr.room_name as new_room_name
                        FROM substitutions s
                        JOIN classes c ON s.class_id = c.class_id
                        LEFT JOIN subjects ns ON s.new_subject_id = ns.subject_id
                        LEFT JOIN rooms nr ON s.new_room_id = nr.room_id
                        WHERE s.new_teacher_id = :teacher_id 
                            AND s.date = :date 
                            AND s.period_number = :period_number";
        
        $stmtSubAsNew = $this->pdo->prepare($sqlSubAsNew);
        $stmtSubAsNew->execute([
            ':teacher_id' => $teacherId,
            ':date' => $date,
            ':period_number' => $periodNumber
        ]);
        $subAsNew = $stmtSubAsNew->fetch(PDO::FETCH_ASSOC);

        if ($subAsNew) {
            // Der Lehrer hält aktiv eine Vertretung
            // Wir müssen den Typ der Vertretung zurückgeben (z.B. Vertretung, Sonderevent)
            return [
                'status' => $subAsNew['substitution_type'], // z.B. "Vertretung" oder "Sonderevent"
                'data' => $subAsNew
            ];
        }

        // 2. Prüfen, ob der Lehrer regulären Unterricht HÄTTE
        $sqlRegular = "SELECT te.*, s.subject_shortcut, c.class_name, r.room_name
                        FROM timetable_entries te
                        LEFT JOIN subjects s ON te.subject_id = s.subject_id
                        LEFT JOIN classes c ON te.class_id = c.class_id
                        LEFT JOIN rooms r ON te.room_id = r.room_id
                        WHERE te.teacher_id = :teacher_id
                            AND te.year = :year
                            AND te.calendar_week = :calendar_week
                            AND te.day_of_week = :day_of_week
                            AND te.period_number = :period_number";
        
        $stmtRegular = $this->pdo->prepare($sqlRegular);
        $stmtRegular->execute([
            ':teacher_id' => $teacherId,
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':day_of_week' => $dayOfWeek,
            ':period_number' => $periodNumber
        ]);
        $regularEntry = $stmtRegular->fetch(PDO::FETCH_ASSOC);

        if (!$regularEntry) {
            // 3. Kein regulärer Unterricht und keine Vertretung -> Freistunde
            return [
                'status' => 'Freistunde',
                'data' => null
            ];
        }

        // 4. Regulärer Unterricht ist geplant. PRÜFE, ob DIESE Stunde vertreten wird.
        $sqlCheckSub = "SELECT s.*, c.class_name, 
                               os.subject_shortcut as original_subject_shortcut, 
                               ns.subject_shortcut as new_subject_shortcut, 
                               nr.room_name as new_room_name
                        FROM substitutions s
                        JOIN classes c ON s.class_id = c.class_id
                        LEFT JOIN subjects os ON s.original_subject_id = os.subject_id
                        LEFT JOIN subjects ns ON s.new_subject_id = ns.subject_id
                        LEFT JOIN rooms nr ON s.new_room_id = nr.room_id
                        WHERE s.date = :date 
                            AND s.period_number = :period_number
                            AND s.class_id = :class_id";
        
        $stmtCheckSub = $this->pdo->prepare($sqlCheckSub);
        $stmtCheckSub->execute([
            ':date' => $date,
            ':period_number' => $periodNumber,
            ':class_id' => $regularEntry['class_id']
        ]);
        $substitution = $stmtCheckSub->fetch(PDO::FETCH_ASSOC);

        if ($substitution) {
            // 5. Ja, die Stunde wird vertreten (z.B. Entfall, Raumänderung)
            // Der Lehrer ist also NICHT im regulären Raum.
            // Der Status ist der Typ der Vertretung.
            return [
                'status' => $substitution['substitution_type'],
                'data' => $substitution
            ];
        }

        // 6. Kein Treffer in Schritt 1 und 5 -> Der Lehrer hält regulären Unterricht.
        return [
            'status' => 'Unterricht',
            'data' => $regularEntry
        ];
    }
    
    /**
     * NEU: Holt alle eindeutigen Klassen-IDs, die ein Lehrer unterrichtet.
     * @param int $teacherId
     * @return array
     */
    public function getClassesForTeacher(int $teacherId): array
    {
        // Holt Klassen aus regulären Einträgen
        $sqlEntries = "SELECT DISTINCT c.class_id, c.class_name 
                        FROM timetable_entries te
                        JOIN classes c ON te.class_id = c.class_id
                        WHERE te.teacher_id = :teacher_id";
                        
        // Holt Klassen aus Vertretungen (wo der Lehrer der *neue* Lehrer ist)
        $sqlSubs = "SELECT DISTINCT c.class_id, c.class_name
                    FROM substitutions s
                    JOIN classes c ON s.class_id = c.class_id
                    WHERE s.new_teacher_id = :teacher_id";

        try {
            $stmtEntries = $this->pdo->prepare($sqlEntries);
            $stmtEntries->execute([':teacher_id' => $teacherId]);
            $classesFromEntries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtSubs = $this->pdo->prepare($sqlSubs);
            $stmtSubs->execute([':teacher_id' => $teacherId]);
            $classesFromSubs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

            // Kombiniere die Ergebnisse und entferne Duplikate (basierend auf class_id)
            $allClasses = [];
            foreach (array_merge($classesFromEntries, $classesFromSubs) as $class) {
                $allClasses[$class['class_id']] = $class; // Nutzen der ID als Schlüssel entfernt Duplikate
            }
            
            // Sortiere nach Klassen-ID
            ksort($allClasses);

            return array_values($allClasses); // Gebe nur die Werte (die Klassen-Arrays) zurück

        } catch (Exception $e) {
            error_log("Fehler beim Abrufen der Klassen für Lehrer {$teacherId}: " . $e->getMessage());
            return [];
        }
    }
}
