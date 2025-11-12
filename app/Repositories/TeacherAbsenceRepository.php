<?php
namespace App\Repositories;
use PDO;
use Exception;
use DateTime;
use DateTimeZone;
class TeacherAbsenceRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function getAbsencesForPeriod(string $startDate, string $endDate): array
    {
        $sql = "SELECT ta.*, t.teacher_shortcut, t.first_name, t.last_name
                FROM teacher_absences ta
                JOIN teachers t ON ta.teacher_id = t.teacher_id
                WHERE ta.start_date <= :end_date AND ta.end_date >= :start_date
                ORDER BY ta.start_date ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getAbsenceById(int $absenceId): array|false
    {
        $sql = "SELECT ta.*, t.teacher_shortcut, t.first_name, t.last_name
                FROM teacher_absences ta
                JOIN teachers t ON ta.teacher_id = t.teacher_id
                WHERE ta.absence_id = :absence_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':absence_id' => $absenceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function createAbsence(?int $absenceId, int $teacherId, string $startDate, string $endDate, string $reason, ?string $comment): array
    {
        if ($startDate > $endDate) {
            throw new Exception("Das Startdatum darf nicht nach dem Enddatum liegen.");
        }
        if (empty($teacherId) || empty($reason)) {
             throw new Exception("Lehrer und Grund sind Pflichtfelder.");
        }
        if ($absenceId) {
            $sql = "UPDATE teacher_absences SET
                        teacher_id = :teacher_id,
                        start_date = :start_date,
                        end_date = :end_date,
                        reason = :reason,
                        comment = :comment
                    WHERE absence_id = :absence_id";
            $params = [
                ':teacher_id' => $teacherId,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':reason' => $reason,
                ':comment' => $comment,
                ':absence_id' => $absenceId
            ];
        } else {
            $sql = "INSERT INTO teacher_absences (teacher_id, start_date, end_date, reason, comment)
                    VALUES (:teacher_id, :start_date, :end_date, :reason, :comment)";
            $params = [
                ':teacher_id' => $teacherId,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':reason' => $reason,
                ':comment' => $comment
            ];
        }
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            throw new Exception("Datenbankfehler beim Speichern der Abwesenheit.");
        }
        $newId = $absenceId ?? (int)$this->pdo->lastInsertId();
        $savedData = $this->getAbsenceById($newId);
        if (!$savedData) {
            throw new Exception("Fehler beim Abrufen der gespeicherten Abwesenheit.");
        }
        return $savedData;
    }
    public function deleteAbsence(int $absenceId): bool
    {
        $sql = "DELETE FROM teacher_absences WHERE absence_id = :absence_id";
        $stmt = $this->pdo->prepare($sql);
        if (!$stmt->execute([':absence_id' => $absenceId])) {
            throw new Exception("Datenbankfehler beim Löschen der Abwesenheit.");
        }
        return $stmt->rowCount() > 0;
    }
    public function getAbsencesForDateRange(string $startDate, string $endDate): array
    {
        $sql = "SELECT teacher_id, start_date, end_date, reason
                FROM teacher_absences
                WHERE start_date <= :end_date AND end_date >= :start_date";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function checkAbsence(int $teacherId, string $date)
    {
        $sql = "SELECT * FROM teacher_absences
                WHERE teacher_id = :teacher_id
                  AND :date BETWEEN start_date AND end_date
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_id' => $teacherId, ':date' => $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function getAbsenceTypes(): array
    {
        return [
            'Krank',
            'Fortbildung',
            'Beurlaubt',
            'Sonstiges'
        ];
    }
}