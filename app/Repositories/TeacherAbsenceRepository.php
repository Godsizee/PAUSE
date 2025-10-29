<?php
// app/Repositories/TeacherAbsenceRepository.php

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

    /**
     * Holt alle Abwesenheiten für den Kalender für einen bestimmten Zeitraum.
     * (KORRIGIERT: Akzeptiert jetzt Parameter)
     *
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAbsencesForPeriod(string $startDate, string $endDate): array
    {
        // KORREKTUR: Verwendet die übergebenen Daten anstelle eines festen Zeitraums
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

    /**
     * Holt eine einzelne Abwesenheit anhand ihrer ID.
     *
     * @param int $absenceId
     * @return array|false
     */
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


    /**
     * Speichert (Insert/Update) eine Abwesenheit.
     *
     * @param int|null $absenceId
     * @param int $teacherId
     * @param string $startDate
     * @param string $endDate
     * @param string $reason
     * @param string|null $comment
     * @return array Der gespeicherte Datensatz
     * @throws Exception
     */
    public function createAbsence(?int $absenceId, int $teacherId, string $startDate, string $endDate, string $reason, ?string $comment): array
    {
        // Validierung der Daten
        if ($startDate > $endDate) {
            throw new Exception("Das Startdatum darf nicht nach dem Enddatum liegen.");
        }
        if (empty($teacherId) || empty($reason)) {
             throw new Exception("Lehrer und Grund sind Pflichtfelder.");
        }

        if ($absenceId) {
            // Update
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
            // Insert
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
        
        // Hole den gespeicherten Datensatz zurück
        $savedData = $this->getAbsenceById($newId);
        if (!$savedData) {
            throw new Exception("Fehler beim Abrufen der gespeicherten Abwesenheit.");
        }
        return $savedData;
    }

    /**
     * Löscht eine Abwesenheit.
     *
     * @param int $absenceId
     * @return bool
     * @throws Exception
     */
    public function deleteAbsence(int $absenceId): bool
    {
        $sql = "DELETE FROM teacher_absences WHERE absence_id = :absence_id";
        $stmt = $this->pdo->prepare($sql);
        
        if (!$stmt->execute([':absence_id' => $absenceId])) {
            throw new Exception("Datenbankfehler beim Löschen der Abwesenheit.");
        }
        
        return $stmt->rowCount() > 0;
    }

    /**
     * Holt Abwesenheiten für einen bestimmten Zeitraum (für den PlanController).
     *
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAbsencesForDateRange(string $startDate, string $endDate): array
    {
        $sql = "SELECT teacher_id, start_date, end_date, reason
                FROM teacher_absences
                WHERE start_date <= :end_date AND end_date >= :start_date";
                
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Prüft, ob ein spezifischer Lehrer an einem spezifischen Tag abwesend ist.
     *
     * @param int $teacherId
     * @param string $date (Y-m-d)
     * @return array|false Die Abwesenheitsdaten oder false
     */
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

    /**
     * Holt die definierten Abwesenheitstypen.
     * Aktuell hardcodiert, könnte später aus einer DB-Tabelle kommen.
     *
     * @return array
     */
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