<?php
namespace App\Repositories;
use PDO;
use Exception;
use DateTime;
use DateTimeZone; 
use PDOException; 
use App\Repositories\TeacherAbsenceRepository; 
class PlanRepository
{
    private PDO $pdo;
    private TeacherAbsenceRepository $absenceRepo; 
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->absenceRepo = new TeacherAbsenceRepository($pdo); 
    }
    private function getWeekDateRange(int $year, int $week): array
    {
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1); 
        $startDate = $dto->format('Y-m-d');
        $dto->setISODate($year, $week, 5); 
        $endDate = $dto->format('Y-m-d');
        return [$startDate, $endDate];
    }
    public function getPublishedTimetableForClass(int $classId, int $year, int $calendarWeek): array
    {
        if (!$this->isWeekPublishedFor('student', $year, $calendarWeek)) {
            return []; 
        }
        return $this->getTimetableForClassAsPlaner($classId, $year, $calendarWeek);
    }
    public function getPublishedTimetableForTeacher(int $teacherId, int $year, int $calendarWeek): array
    {
        if (!$this->isWeekPublishedFor('teacher', $year, $calendarWeek)) {
            return []; 
        }
        return $this->getTimetableForTeacherAsPlaner($teacherId, $year, $calendarWeek);
    }
    public function getPublishedSubstitutionsForClassWeek(int $classId, int $year, int $calendarWeek): array
    {
        if (!$this->isWeekPublishedFor('student', $year, $calendarWeek)) {
            return [];
        }
        return $this->getSubstitutionsForClassWeekAsPlaner($classId, $year, $calendarWeek);
    }
    public function getPublishedSubstitutionsForTeacherWeek(int $teacherId, int $year, int $calendarWeek): array
    {
        if (!$this->isWeekPublishedFor('teacher', $year, $calendarWeek)) {
            return [];
        }
        return $this->getSubstitutionsForTeacherWeekAsPlaner($teacherId, $year, $calendarWeek);
    }
    public function getTimetableForClassAsPlaner(int $classId, int $year, int $calendarWeek): array
    {
        $sql = "SELECT te.*, s.subject_shortcut, s.subject_name, t.teacher_shortcut, r.room_name, c.class_name
                FROM timetable_entries te
                LEFT JOIN subjects s ON te.subject_id = s.subject_id
                LEFT JOIN teachers t ON te.teacher_id = t.teacher_id
                LEFT JOIN rooms r ON te.room_id = r.room_id
                LEFT JOIN classes c ON te.class_id = c.class_id
                WHERE te.class_id = :class_id
                    AND te.year = :year
                    AND te.calendar_week = :calendar_week
                ORDER BY te.day_of_week ASC, te.period_number ASC, te.entry_id ASC"; 
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':class_id' => $classId, ':year' => $year, ':calendar_week' => $calendarWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getTimetableForTeacherAsPlaner(int $teacherId, int $year, int $calendarWeek): array
    {
        $sql = "SELECT te.*, s.subject_shortcut, s.subject_name, c.class_name, r.room_name, t.teacher_shortcut
                FROM timetable_entries te
                LEFT JOIN subjects s ON te.subject_id = s.subject_id
                LEFT JOIN classes c ON te.class_id = c.class_id
                LEFT JOIN rooms r ON te.room_id = r.room_id
                JOIN teachers t ON te.teacher_id = t.teacher_id
                WHERE te.teacher_id = :teacher_id
                    AND te.year = :year
                    AND te.calendar_week = :calendar_week
                ORDER BY te.day_of_week ASC, te.period_number ASC, te.entry_id ASC"; 
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_id' => $teacherId, ':year' => $year, ':calendar_week' => $calendarWeek]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getSubstitutionsForClassWeekAsPlaner(int $classId, int $year, int $calendarWeek): array
    {
        return $this->getSubstitutionsForWeekInternal($year, $calendarWeek, $classId, null);
    }
    public function getSubstitutionsForTeacherWeekAsPlaner(int $teacherId, int $year, int $calendarWeek): array
    {
        return $this->getSubstitutionsForWeekInternal($year, $calendarWeek, null, $teacherId);
    }
    private function getSubstitutionsForWeekInternal(int $year, int $calendarWeek, ?int $classId, ?int $teacherId): array
    {
        [$startDate, $endDate] = $this->getWeekDateRange($year, $calendarWeek);
        $sql = "SELECT
                                s.*,
                                DAYOFWEEK(s.date) as day_of_week_iso, 
                                CASE DAYOFWEEK(s.date) WHEN 1 THEN NULL WHEN 7 THEN NULL ELSE DAYOFWEEK(s.date) - 1 END as day_of_week, 
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
            $params[':year'] = $year; 
            $params[':calendar_week'] = $calendarWeek;
        }
        $sql .= " ORDER BY s.date ASC, s.period_number ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function publishWeek(int $year, int $calendarWeek, string $targetGroup, int $userId): bool
    {
        $sql = "INSERT INTO timetable_publish_status (year, calendar_week, target_group, published_at, publisher_user_id)
                VALUES (:year, :calendar_week, :target_group, NOW(), :user_id)
                ON DUPLICATE KEY UPDATE published_at = NOW(), publisher_user_id = VALUES(publisher_user_id)"; 
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':target_group' => $targetGroup,
            ':user_id' => $userId
        ]);
    }
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
    public function getPublishStatus(int $year, int $calendarWeek): array
    {
        $sql = "SELECT target_group FROM timetable_publish_status
                WHERE year = :year AND calendar_week = :calendar_week";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':year' => $year, ':calendar_week' => $calendarWeek]);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return ['student' => in_array('student', $results), 'teacher' => in_array('teacher', $results)];
    }
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
    public function deleteEntry(int $entryId): bool
    {
        $sql = "DELETE FROM timetable_entries WHERE entry_id = :entry_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':entry_id' => $entryId]);
    }
    public function deleteEntryBlock(string $blockId): bool
    {
        $sql = "DELETE FROM timetable_entries WHERE block_id = :block_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':block_id' => $blockId]);
    }
    public function createOrUpdateEntry(array $data): array
    {
        $required = ['year', 'calendar_week', 'day_of_week', 'teacher_id', 'subject_id', 'room_id'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new Exception("Fehlende Daten: Feld '{$field}' ist erforderlich und darf nicht leer sein.");
            }
        }
        if (!isset($data['class_id'])) {
             throw new Exception("Fehlende Daten: Feld 'class_id' ist erforderlich.");
        }
        $comment = isset($data['comment']) ? trim($data['comment']) : null;
        if ($comment === '') {
            $comment = null; 
        }
        $startPeriod = (int)($data['start_period_number'] ?? $data['period_number'] ?? 0);
        $endPeriod = (int)($data['end_period_number'] ?? $data['period_number'] ?? 0);
        $data['start_period_number'] = $startPeriod;
        $data['end_period_number'] = $endPeriod;
        if ($startPeriod <= 0 || $endPeriod <= 0 || $startPeriod > $endPeriod) {
            throw new Exception("Ungültiger Stundenbereich (Start/Ende > 0 und Start <= Ende erforderlich).");
        }
        $excludeEntryId = !empty($data['entry_id']) ? (int)$data['entry_id'] : null;
        $excludeBlockId = !empty($data['block_id']) ? (string)$data['block_id'] : null;
        $this->checkConflicts($data, $excludeEntryId, $excludeBlockId);
        $this->pdo->beginTransaction();
        try {
            if (!empty($data['entry_id']) && filter_var($data['entry_id'], FILTER_VALIDATE_INT)) {
                $deleteSql = "DELETE FROM timetable_entries WHERE entry_id = :entry_id";
                $deleteParams = [':entry_id' => $data['entry_id']];
                $deleteStmt = $this->pdo->prepare($deleteSql);
                $deleteStmt->execute($deleteParams);
            } elseif (!empty($data['block_id'])) {
                $deleteSql = "DELETE FROM timetable_entries WHERE block_id = :block_id";
                $deleteParams = [':block_id' => $data['block_id']];
                $deleteStmt = $this->pdo->prepare($deleteSql);
                $deleteStmt->execute($deleteParams);
            }
            $blockId = ($startPeriod !== $endPeriod) ? uniqid('block_', true) : null;
            $insertSql = "INSERT INTO timetable_entries (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment)
                            VALUES (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";
            $insertStmt = $this->pdo->prepare($insertSql);
            $insertedIds = []; 
            for ($period = $startPeriod; $period <= $endPeriod; $period++) {
                $params = [
                    ':year' => $data['year'],
                    ':calendar_week' => $data['calendar_week'],
                    ':day_of_week' => $data['day_of_week'],
                    ':period_number' => $period,
                    ':class_id' => $data['class_id'], 
                    ':teacher_id' => $data['teacher_id'],
                    ':subject_id' => $data['subject_id'],
                    ':room_id' => $data['room_id'],
                    ':block_id' => $blockId,
                    ':comment' => $comment
                ];
                if (!$insertStmt->execute($params)) {
                    $errorInfo = $insertStmt->errorInfo();
                    throw new PDOException("Fehler beim Einfügen von Stunde {$period}. SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
                }
                $insertedIds[] = $this->pdo->lastInsertId(); 
            }
            $this->pdo->commit();
            return [
                'block_id' => $blockId,
                'entry_ids' => $insertedIds, 
                'periods' => range($startPeriod, $endPeriod)
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("PlanRepository::createOrUpdateEntry failed: " . $e->getMessage());
            throw new Exception("Fehler beim Speichern des Stundenplaneintrags: " . $e->getMessage());
        }
    }
    public function createOrUpdateSubstitution(array $data): array
    {
        if (empty($data['date']) || empty($data['period_number']) || !isset($data['class_id']) || empty($data['substitution_type'])) {
            throw new Exception("Datum, Stunde, Klasse und Vertretungstyp sind Pflichtfelder.");
        }
        if (DateTime::createFromFormat('Y-m-d', $data['date']) === false) {
            throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.");
        }
        if (!empty($data['new_teacher_id'])) {
            $dateObj = new DateTime($data['date']);
            $conflictData = [
                'year' => (int)$dateObj->format('o'),
                'calendar_week' => (int)$dateObj->format('W'),
                'day_of_week' => (int)$dateObj->format('N'),
                'start_period_number' => $data['period_number'],
                'end_period_number' => $data['period_number'],
                'teacher_id' => $data['new_teacher_id'],
                'room_id' => null, 
                'class_id' => $data['class_id'] 
            ];
            try {
                $this->checkConflicts($conflictData, null, null);
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'LEHRER-KONFLIKT')) {
                    throw new Exception("KONFLIKT: Dieser Lehrer hält bereits regulären Unterricht in einer anderen Klasse.", 409, $e);
                }
                throw $e; 
            }
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
            $absence = $this->absenceRepo->checkAbsence($data['new_teacher_id'], $data['date']);
            if ($absence) {
                throw new Exception("KONFLIKT: Der Vertretungslehrer (ID {$data['new_teacher_id']}) ist an diesem Tag als '{$absence['reason']}' gemeldet.", 409);
            }
        }
        $nullableFields = ['original_subject_id', 'new_teacher_id', 'new_subject_id', 'new_room_id', 'comment'];
        foreach ($nullableFields as $field) {
            if (isset($data[$field])) {
                $value = trim($data[$field]);
                if ($value === '' || ($value === '0' && $field !== 'comment')) {
                    $data[$field] = null;
                } else {
                    $data[$field] = $value; 
                }
            } else {
                $data[$field] = null; 
            }
        }
        if (!empty($data['substitution_id']) && filter_var($data['substitution_id'], FILTER_VALIDATE_INT)) {
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
            $sql = "INSERT INTO substitutions (date, period_number, class_id, substitution_type, original_subject_id, new_teacher_id, new_subject_id, new_room_id, comment)
                    VALUES (:date, :period_number, :class_id, :substitution_type, :original_subject_id, :new_teacher_id, :new_subject_id, :new_room_id, :comment)";
            $currentId = null; 
        }
        $stmt = $this->pdo->prepare($sql);
        $params = [
            ':date' => $data['date'],
            ':period_number' => $data['period_number'],
            ':class_id' => $data['class_id'], 
            ':substitution_type' => $data['substitution_type'],
            ':original_subject_id' => $data['original_subject_id'],
            ':new_teacher_id' => $data['new_teacher_id'],
            ':new_subject_id' => $data['new_subject_id'],
            ':new_room_id' => $data['new_room_id'],
            ':comment' => $data['comment'], 
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
        $savedData = $this->getSubstitutionById($currentId);
        if (!$savedData) {
            $data['substitution_id'] = $currentId;
            try {
                $dateObj = new DateTime($data['date']);
                $dayOfWeek = $dateObj->format('N'); 
                $data['day_of_week'] = ($dayOfWeek >= 1 && $dayOfWeek <= 5) ? $dayOfWeek : null;
                $data['day_of_week_iso'] = $dateObj->format('N'); 
            } catch (Exception $e) {
                $data['day_of_week'] = null;
                $data['day_of_week_iso'] = null;
            }
            return $data;
        }
        return $savedData;
    }
    public function getSubstitutionById(int $substitutionId): array|false
    {
        $sql = "SELECT
                                        s.*,
                                        DAYOFWEEK(s.date) as day_of_week_iso, 
                                        CASE DAYOFWEEK(s.date) WHEN 1 THEN NULL WHEN 7 THEN NULL ELSE DAYOFWEEK(s.date) - 1 END as day_of_week, 
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
    public function deleteSubstitution(int $substitutionId): bool
    {
        $sql = "DELETE FROM substitutions WHERE substitution_id = :substitution_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':substitution_id' => $substitutionId]);
    }
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
        $exclusionSql = "";
        if ($excludeEntryId !== null) {
            $exclusionSql = " AND te.entry_id != :exclude_entry_id";
            $params[':exclude_entry_id'] = $excludeEntryId;
        } elseif ($excludeBlockId !== null) {
            $exclusionSql = " AND te.block_id != :exclude_block_id";
            $params[':exclude_block_id'] = $excludeBlockId;
        }
        $dateForAbsenceCheck = '';
        try {
            $dto = new DateTime();
            $dto->setISODate($data['year'], $data['calendar_week'], $data['day_of_week']);
            $dateForAbsenceCheck = $dto->format('Y-m-d');
        } catch (Exception $e) {
            throw new Exception("Interner Fehler: Datum für Konfliktprüfung konnte nicht berechnet werden.");
        }
        if (!empty($data['teacher_id'])) {
            $absence = $this->absenceRepo->checkAbsence($data['teacher_id'], $dateForAbsenceCheck);
            if ($absence) {
                $conflicts[] = "LEHRER-KONFLIKT: Lehrer (ID {$data['teacher_id']}) ist an diesem Tag als '{$absence['reason']}' gemeldet.";
            }
            $teacherSql = $baseSql . " AND te.teacher_id = :teacher_id" . $exclusionSql;
            $teacherParams = $params + [':teacher_id' => $data['teacher_id']];
            if ($excludeEntryId === null) unset($teacherParams[':exclude_entry_id']);
            if ($excludeBlockId === null) unset($teacherParams[':exclude_block_id']);
            $stmtTeacher = $this->pdo->prepare($teacherSql);
            $stmtTeacher->execute($teacherParams);
            $existingTeacherEntry = $stmtTeacher->fetch(PDO::FETCH_ASSOC);
            if ($existingTeacherEntry) {
                $shortcut = $existingTeacherEntry['teacher_shortcut'] ?: $data['teacher_id'];
                $conflicts[] = "LEHRER-KONFLIKT: '{$shortcut}' ist bereits in Klasse {$existingTeacherEntry['class_name']} ({$existingTeacherEntry['room_name']}) eingeteilt.";
            }
        }
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
                $conflicts[] = "RAUM-KONFLIKT: '{$name}' ist bereits von Klasse {$existingRoomEntry['class_name']} (Lehrer: {$existingRoomEntry['teacher_shortcut']}) belegt.";
            }
        }
        if (!empty($conflicts)) {
            throw new Exception(implode("\n", $conflicts));
        }
        return $conflicts; 
    }
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
                $this->pdo->rollBack(); 
                return 0; 
            }
            $insertSql = "INSERT INTO timetable_entries 
                            (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment) 
                            VALUES 
                            (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";
            $stmtInsert = $this->pdo->prepare($insertSql);
            $copiedCount = 0;
            $blockIdMap = []; 
            foreach ($sourceEntries as $entry) {
                $newBlockId = null;
                if ($entry['block_id']) {
                    if (!isset($blockIdMap[$entry['block_id']])) {
                        $blockIdMap[$entry['block_id']] = uniqid('block_', true);
                    }
                    $newBlockId = $blockIdMap[$entry['block_id']];
                }
                $success = $stmtInsert->execute([
                    ':year' => $targetYear, 
                    ':calendar_week' => $targetWeek, 
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entry['class_id'],
                    ':teacher_id' => $entry['teacher_id'],
                    ':subject_id' => $entry['subject_id'],
                    ':room_id' => $entry['room_id'],
                    ':block_id' => $newBlockId, 
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
    public function createTemplate(string $name, ?string $description, array $sourceEntries): int
    {
        if (empty($name)) {
            throw new Exception("Vorlagenname darf nicht leer sein.");
        }
        if (empty($sourceEntries)) {
            throw new Exception("Vorlage muss mindestens einen Eintrag enthalten.");
        }
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_templates WHERE name = :name");
        $stmtCheck->execute([':name' => $name]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new Exception("Eine Vorlage mit dem Namen '{$name}' existiert bereits.", 409); 
        }
        $this->pdo->beginTransaction();
        try {
            $sqlTemplate = "INSERT INTO timetable_templates (name, description) VALUES (:name, :description)";
            $stmtTemplate = $this->pdo->prepare($sqlTemplate);
            $stmtTemplate->execute([':name' => $name, ':description' => $description]);
            $templateId = (int)$this->pdo->lastInsertId();
            $sqlEntry = "INSERT INTO timetable_template_entries
                            (template_id, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_ref, comment)
                            VALUES
                            (:template_id, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_ref, :comment)";
            $stmtEntry = $this->pdo->prepare($sqlEntry);
            $blockRefMap = []; 
            foreach ($sourceEntries as $entry) {
                $blockRef = null;
                if ($entry['block_id']) {
                    if (!isset($blockRefMap[$entry['block_id']])) {
                        $blockRefMap[$entry['block_id']] = uniqid('tpl_blk_', true); 
                    }
                    $blockRef = $blockRefMap[$entry['block_id']];
                }
                $stmtEntry->execute([
                    ':template_id' => $templateId,
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entry['class_id'], 
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
            if (str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() == 409) {
                throw new Exception("Eine Vorlage mit dem Namen '{$name}' existiert bereits.", 409);
            }
            throw new Exception("Fehler beim Erstellen der Vorlage: " . $e->getMessage());
        }
    }
    public function getTemplates(): array
    {
        $stmt = $this->pdo->query("SELECT template_id, name, description FROM timetable_templates ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function applyTemplateToWeek(int $templateId, int $targetYear, int $targetWeek, ?int $targetClassId, ?int $targetTeacherId): int
    {
        if ($targetClassId === null && $targetTeacherId === null) {
            throw new Exception("Es muss entweder eine Klasse oder ein Lehrer als Ziel angegeben werden.");
        }
        $stmtFetch = $this->pdo->prepare("SELECT * FROM timetable_template_entries WHERE template_id = :template_id ORDER BY day_of_week, period_number");
        $stmtFetch->execute([':template_id' => $templateId]);
        $templateEntries = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);
        if (empty($templateEntries)) {
            return 0;
        }
        $this->pdo->beginTransaction();
        try {
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
            $insertSql = "INSERT INTO timetable_entries
                            (year, calendar_week, day_of_week, period_number, class_id, teacher_id, subject_id, room_id, block_id, comment)
                            VALUES
                            (:year, :calendar_week, :day_of_week, :period_number, :class_id, :teacher_id, :subject_id, :room_id, :block_id, :comment)";
            $stmtInsert = $this->pdo->prepare($insertSql);
            $appliedCount = 0;
            $blockIdMap = []; 
            foreach ($templateEntries as $entry) {
                $newBlockId = null;
                if ($entry['block_ref']) {
                    if (!isset($blockIdMap[$entry['block_ref']])) {
                        $blockIdMap[$entry['block_ref']] = uniqid('block_', true); 
                    }
                    $newBlockId = $blockIdMap[$entry['block_ref']];
                }
                $entryClassId = ($entityIdField === 'class_id') ? $entityIdValue : $entry['class_id'];
                if ($entityIdField === 'teacher_id' && (empty($entryClassId) || $entryClassId == 0)) {
                    error_log("Template apply skipped: Teacher template entry missing class_id. TemplateEntryID: " . $entry['template_entry_id']);
                    continue; 
                }
                $success = $stmtInsert->execute([
                    ':year' => $targetYear,
                    ':calendar_week' => $targetWeek,
                    ':day_of_week' => $entry['day_of_week'],
                    ':period_number' => $entry['period_number'],
                    ':class_id' => $entryClassId, 
                    ':teacher_id' => $entry['teacher_id'],
                    ':subject_id' => $entry['subject_id'],
                    ':room_id' => $entry['room_id'],
                    ':block_id' => $newBlockId, 
                    ':comment' => $entry['comment']
                ]);
                if ($success) {
                    $appliedCount++;
                } else {
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
    public function deleteTemplate(int $templateId): bool
    {
        $sql = "DELETE FROM timetable_templates WHERE template_id = :template_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':template_id' => $templateId]);
    }
    public function loadTemplateDetails(int $templateId): array
    {
        $stmtTemplate = $this->pdo->prepare("SELECT * FROM timetable_templates WHERE template_id = :id");
        $stmtTemplate->execute([':id' => $templateId]);
        $templateInfo = $stmtTemplate->fetch(PDO::FETCH_ASSOC);
        if (!$templateInfo) {
            throw new Exception("Vorlage nicht gefunden.");
        }
        $stmtEntries = $this->pdo->prepare("SELECT * FROM timetable_template_entries WHERE template_id = :id ORDER BY day_of_week, period_number");
        $stmtEntries->execute([':id' => $templateId]);
        $entries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
        return [
            'template' => $templateInfo,
            'entries' => $entries
        ];
    }
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
            $sqlCheckName = "SELECT template_id FROM timetable_templates WHERE name = :name AND (:id IS NULL OR template_id != :id)";
            $stmtCheckName = $this->pdo->prepare($sqlCheckName);
            $stmtCheckName->execute([':name' => $name, ':id' => $templateId]);
            if ($stmtCheckName->fetch()) {
                throw new Exception("Eine andere Vorlage mit diesem Namen existiert bereits.", 409);
            }
            if ($templateId) {
                $sqlTemplate = "UPDATE timetable_templates SET name = :name, description = :description WHERE template_id = :id";
                $stmtTemplate = $this->pdo->prepare($sqlTemplate);
                $stmtTemplate->execute([':name' => $name, ':description' => $description, ':id' => $templateId]);
            } else {
                $sqlTemplate = "INSERT INTO timetable_templates (name, description) VALUES (:name, :description)";
                $stmtTemplate = $this->pdo->prepare($sqlTemplate);
                $stmtTemplate->execute([':name' => $name, ':description' => $description]);
                $templateId = (int)$this->pdo->lastInsertId();
            }
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
            error_log("PlanRepository::saveTemplateDetails failed: " . $e->getMessage());
            $errorCode = $e->getCode() == 409 ? 409 : 500;
            throw new Exception("Fehler beim Speichern der Vorlage: " . $e->getMessage(), $errorCode);
        }
    }
    public function findTeacherLocation(int $teacherId, string $date, int $year, int $calendarWeek, int $dayOfWeek, int $periodNumber): array
    {
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
            return [
                'status' => $subAsNew['substitution_type'], 
                'data' => $subAsNew
            ];
        }
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
        $regularEntries = $stmtRegular->fetchAll(PDO::FETCH_ASSOC);
        if (empty($regularEntries)) {
            return [
                'status' => 'Freistunde',
                'data' => null
            ];
        }
        foreach ($regularEntries as $index => $regularEntry) {
            if (empty($regularEntry['class_id'])) {
                 continue;
            }
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
                $regularEntries[$index]['substitution_info'] = $substitution;
            }
        }
        if (count($regularEntries) === 1 && isset($regularEntries[0]['substitution_info'])) {
             return [
                'status' => $regularEntries[0]['substitution_info']['substitution_type'], 
                'data' => $regularEntries[0]['substitution_info']
            ];
        }
        return [
            'status' => 'Unterricht',
            'data' => $regularEntries 
        ];
    }
    public function getClassesForTeacher(int $teacherId): array
    {
        $sqlEntries = "SELECT DISTINCT c.class_id, c.class_name 
                        FROM timetable_entries te
                        JOIN classes c ON te.class_id = c.class_id
                        WHERE te.teacher_id = :teacher_id AND te.class_id IS NOT NULL AND te.class_id != 0";
        $sqlSubs = "SELECT DISTINCT c.class_id, c.class_name
                    FROM substitutions s
                    JOIN classes c ON s.class_id = c.class_id
                    WHERE s.new_teacher_id = :teacher_id AND s.class_id IS NOT NULL AND s.class_id != 0";
        try {
            $stmtEntries = $this->pdo->prepare($sqlEntries);
            $stmtEntries->execute([':teacher_id' => $teacherId]);
            $classesFromEntries = $stmtEntries->fetchAll(PDO::FETCH_ASSOC);
            $stmtSubs = $this->pdo->prepare($sqlSubs);
            $stmtSubs->execute([':teacher_id' => $teacherId]);
            $classesFromSubs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
            $allClasses = [];
            foreach (array_merge($classesFromEntries, $classesFromSubs) as $class) {
                $allClasses[$class['class_id']] = $class; 
            }
            ksort($allClasses);
            return array_values($allClasses); 
        } catch (Exception $e) {
            error_log("Fehler beim Abrufen der Klassen für Lehrer {$teacherId}: " . $e->getMessage());
            return [];
        }
    }
}