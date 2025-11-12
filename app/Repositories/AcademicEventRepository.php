<?php
namespace App\Repositories;
use PDO;
use Exception;
use DateTime; 
class AcademicEventRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function getEventsForClassByWeek(int $classId, int $year, int $week): array
    {
        $monday = new DateTime();
        $monday->setISODate($year, $week, 1); 
        $startDate = $monday->format('Y-m-d');
        $sunday = new DateTime();
        $sunday->setISODate($year, $week, 7); 
        $endDate = $sunday->format('Y-m-d');
        return $this->getEventsForClassByDateRange($classId, $startDate, $endDate);
    }
    public function getEventsForClassByDateRange(int $classId, string $startDate, string $endDate): array
    {
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
    public function getEventsByTeacher(int $teacherUserId, int $daysInFuture = 14): array
    {
        $startDate = (new DateTime('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $endDate = (new DateTime('now', new \DateTimeZone('Europe/Berlin')))->modify("+{$daysInFuture} days")->format('Y-m-d');
        return $this->getEventsByTeacherForDateRange($teacherUserId, $startDate, $endDate);
    }
    public function getEventsByTeacherForDateRange(int $teacherUserId, string $startDate, string $endDate): array
    {
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
    public function checkTeacherAuthorization(int $teacherId, int $classId, string $date): bool
    {
        try {
            $dateObj = new DateTime($date);
            $year = (int)$dateObj->format('o');
            $week = (int)$dateObj->format('W');
            $dayOfWeek = (int)$dateObj->format('N'); 
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
                return true; 
            }
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
                return true; 
            }
            return false; 
        } catch (Exception $e) {
            error_log("Fehler bei checkTeacherAuthorization: " . $e->getMessage());
            return false; 
        }
    }
    public function saveEvent(?int $eventId, int $teacherUserId, int $classId, ?int $subjectId, string $eventType, string $title, string $dueDate, ?string $description): array
    {
        if ($eventId) {
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
        $savedEvent = $this->getEventById($eventId);
        if (!$savedEvent) {
            throw new Exception("Fehler beim Abrufen des gespeicherten Events.");
        }
        return $savedEvent;
    }
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