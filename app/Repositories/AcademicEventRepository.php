<?php
// app/Repositories/AcademicEventRepository.php

namespace App\Repositories;

use PDO;
use Exception;
use DateTime; // Wichtig für Datumsberechnungen

class AcademicEventRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Holt alle Events (Aufgaben, Klausuren) für eine bestimmte Klasse in einem Zeitraum.
     * @param int $classId
     * @param int $year
     * @param int $week
     * @return array
     */
    public function getEventsForClassByWeek(int $classId, int $year, int $week): array
    {
        // Berechne Start- und Enddatum der Woche (Mo-So)
        $monday = new DateTime();
        $monday->setISODate($year, $week, 1); // 1 = Montag
        $startDate = $monday->format('Y-m-d');

        $sunday = new DateTime();
        $sunday->setISODate($year, $week, 7); // 7 = Sonntag
        $endDate = $sunday->format('Y-m-d');

        // Nutze die neue, allgemeinere Funktion
        return $this->getEventsForClassByDateRange($classId, $startDate, $endDate);
    }

    /**
     * Holt Events für eine Klasse in einem Datumsbereich.
     * @param int $classId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getEventsForClassByDateRange(int $classId, string $startDate, string $endDate): array
    {
        // KORREKTUR: ae.period_number aus ORDER BY entfernt
        $sql = "SELECT
                    ae.*,
                    s.subject_shortcut,
                    u.first_name AS teacher_first_name,
                    u.last_name AS teacher_last_name
                FROM academic_events ae
                LEFT JOIN subjects s ON ae.subject_id = s.subject_id
                JOIN users u ON ae.user_id = u.user_id
                WHERE ae.class_id = :class_id
                  AND ae.due_date BETWEEN :start_date AND :end_date
                ORDER BY ae.due_date ASC, ae.event_type ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':class_id' => $classId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Holt alle Events, die ein Lehrer für die nahe Zukunft erstellt hat.
     * @param int $teacherUserId Die user_id des Lehrers
     * @param int $daysInFuture
     * @return array
     */
    public function getEventsByTeacher(int $teacherUserId, int $daysInFuture = 14): array
    {
        $startDate = (new DateTime('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $endDate = (new DateTime('now', new \DateTimeZone('Europe/Berlin')))->modify("+{$daysInFuture} days")->format('Y-m-d');

        // Nutze die neue, allgemeinere Funktion
        return $this->getEventsByTeacherForDateRange($teacherUserId, $startDate, $endDate);
    }

    /**
     * Holt Events, die ein Lehrer erstellt hat, in einem Datumsbereich.
     * @param int $teacherUserId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getEventsByTeacherForDateRange(int $teacherUserId, string $startDate, string $endDate): array
    {
        // KORREKTUR: ae.period_number aus ORDER BY entfernt
         $sql = "SELECT
                    ae.*,
                    s.subject_shortcut,
                    c.class_name
                FROM academic_events ae
                LEFT JOIN subjects s ON ae.subject_id = s.subject_id
                JOIN classes c ON ae.class_id = c.class_id
                WHERE ae.user_id = :teacher_user_id
                  AND ae.due_date BETWEEN :start_date AND :end_date
                ORDER BY ae.due_date ASC, c.class_name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':teacher_user_id' => $teacherUserId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prüft, ob ein Lehrer berechtigt ist, für eine Klasse an einem Datum einen Eintrag zu erstellen.
     * (Prüft, ob der Lehrer an dem Tag Unterricht in der Klasse hat)
     * @param int $teacherId Die teacher_id (aus der teachers Tabelle)
     * @param int $classId
     * @param string $date YYYY-MM-DD
     * @return bool
     */
    public function checkTeacherAuthorization(int $teacherId, int $classId, string $date): bool
    {
        try {
            $dateObj = new DateTime($date);
            $year = (int)$dateObj->format('o');
            $week = (int)$dateObj->format('W');
            $dayOfWeek = (int)$dateObj->format('N'); // 1=Mo, 7=So

            // 1. Prüfen auf regulären Unterricht
            $sqlRegular = "SELECT 1 FROM timetable_entries
                           WHERE teacher_id = :teacher_id
                             AND class_id = :class_id
                             AND year = :year
                             AND calendar_week = :week
                             AND day_of_week = :day_of_week
                           LIMIT 1";

            $stmtRegular = $this->pdo->prepare($sqlRegular);
            $stmtRegular->execute([
                ':teacher_id' => $teacherId,
                ':class_id' => $classId,
                ':year' => $year,
                ':week' => $week,
                ':day_of_week' => $dayOfWeek
            ]);

            if ($stmtRegular->fetchColumn()) {
                return true; // Ja, hat regulären Unterricht
            }

            // 2. Prüfen auf Vertretung (als neuer Lehrer)
            $sqlSub = "SELECT 1 FROM substitutions
                       WHERE new_teacher_id = :teacher_id
                         AND class_id = :class_id
                         AND date = :date
                       LIMIT 1";

            $stmtSub = $this->pdo->prepare($sqlSub);
            $stmtSub->execute([
                ':teacher_id' => $teacherId,
                ':class_id' => $classId,
                ':date' => $date
            ]);

            if ($stmtSub->fetchColumn()) {
                return true; // Ja, hält eine Vertretung
            }

            return false; // Kein Unterricht an diesem Tag in dieser Klasse gefunden

        } catch (Exception $e) {
            error_log("Fehler bei checkTeacherAuthorization: " . $e->getMessage());
            return false; // Im Zweifel ablehnen
        }
    }

    /**
     * Speichert (Insert/Update) ein Event.
     * KORREKTUR: Parameter $period entfernt.
     * @param int|null $eventId
     * @param int $teacherUserId (Dies ist die user_id aus der users Tabelle)
     * @param int $classId
     * @param int|null $subjectId
     * @param string $eventType
     * @param string $title
     * @param string $dueDate
     * @param string|null $description
     * @return array Das gespeicherte Event
     * @throws Exception
     */
    public function saveEvent(?int $eventId, int $teacherUserId, int $classId, ?int $subjectId, string $eventType, string $title, string $dueDate, ?string $description): array
    {
        if ($eventId) {
            // Update
            // KORREKTUR: period_number entfernt
            $sql = "UPDATE academic_events SET
                        class_id = :class_id,
                        subject_id = :subject_id,
                        event_type = :event_type,
                        title = :title,
                        due_date = :due_date,
                        description = :description
                    WHERE event_id = :event_id AND user_id = :teacher_user_id";

            $params = [
                ':event_id' => $eventId,
                ':teacher_user_id' => $teacherUserId,
                ':class_id' => $classId,
                ':subject_id' => $subjectId,
                ':event_type' => $eventType,
                ':title' => $title,
                ':due_date' => $dueDate,
                ':description' => $description
            ];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

        } else {
            // Insert
            // KORREKTUR: period_number entfernt
            $sql = "INSERT INTO academic_events
                        (user_id, class_id, subject_id, event_type, title, due_date, description)
                    VALUES
                        (:teacher_user_id, :class_id, :subject_id, :event_type, :title, :due_date, :description)";

            $params = [
                ':teacher_user_id' => $teacherUserId,
                ':class_id' => $classId,
                ':subject_id' => $subjectId,
                ':event_type' => $eventType,
                ':title' => $title,
                ':due_date' => $dueDate,
                ':description' => $description
            ];
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $eventId = (int)$this->pdo->lastInsertId();
        }

        // Hole den gespeicherten Datensatz (inkl. Joins) für die Rückgabe an das Frontend
        $savedEvent = $this->getEventById($eventId);
        if (!$savedEvent) {
            throw new Exception("Fehler beim Abrufen des gespeicherten Events.");
        }
        return $savedEvent;
    }

    /**
     * Löscht ein Event, wenn es dem Lehrer gehört.
     * @param int $eventId
     * @param int $teacherUserId (Dies ist die user_id)
     * @return bool
     */
    public function deleteEvent(int $eventId, int $teacherUserId): bool
    {
        $sql = "DELETE FROM academic_events
                WHERE event_id = :event_id AND user_id = :teacher_user_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':event_id' => $eventId,
            ':teacher_user_id' => $teacherUserId
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Hilfsfunktion: Holt ein einzelnes Event anhand seiner ID (mit Joins).
     * @param int $eventId
     * @return array|false
     */
    public function getEventById(int $eventId)
    {
         $sql = "SELECT
                    ae.*,
                    s.subject_shortcut,
                    c.class_name,
                    u.first_name AS teacher_first_name,
                    u.last_name AS teacher_last_name
                FROM academic_events ae
                LEFT JOIN subjects s ON ae.subject_id = s.subject_id
                JOIN classes c ON ae.class_id = c.class_id
                JOIN users u ON ae.user_id = u.user_id
                WHERE ae.event_id = :event_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}