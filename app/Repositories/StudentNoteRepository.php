<?php
// app/Repositories/StudentNoteRepository.php

namespace App\Repositories;

use PDO;
use Exception;

class StudentNoteRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Holt alle Notizen eines Schülers für eine bestimmte Woche.
     *
     * @param int $userId
     * @param int $year
     * @param int $calendarWeek
     * @return array Assoziatives Array (z.B. ["1-2" => "Notiz..."])
     */
    public function getNotesForWeek(int $userId, int $year, int $calendarWeek): array
    {
        $sql = "SELECT day_of_week, period_number, note_content
                FROM student_notes
                WHERE user_id = :user_id
                  AND year = :year
                  AND calendar_week = :calendar_week";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':year' => $year,
            ':calendar_week' => $calendarWeek
        ]);

        $notes = [];
        // Erstellt ein einfaches Key-Value-Paar "Tag-Stunde" => "Inhalt"
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['day_of_week'] . '-' . $row['period_number'];
            $notes[$key] = $row['note_content'];
        }
        
        return $notes;
    }

    /**
     * Speichert oder aktualisiert eine Notiz (UPSERT).
     *
     * @param int $userId
     * @param int $year
     * @param int $calendarWeek
     * @param int $dayOfWeek
     * @param int $periodNumber
     * @param string $content
     * @return bool
     */
    public function saveNote(int $userId, int $year, int $calendarWeek, int $dayOfWeek, int $periodNumber, string $content): bool
    {
        // Wenn der Inhalt leer ist, löschen wir den Eintrag, anstatt einen leeren String zu speichern.
        if (empty(trim($content))) {
            return $this->deleteNote($userId, $year, $calendarWeek, $dayOfWeek, $periodNumber);
        }

        $sql = "INSERT INTO student_notes (user_id, `year`, calendar_week, day_of_week, period_number, note_content)
                VALUES (:user_id, :year, :calendar_week, :day_of_week, :period_number, :note_content)
                ON DUPLICATE KEY UPDATE
                    note_content = VALUES(note_content),
                    last_updated = NOW()";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':user_id' => $userId,
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':day_of_week' => $dayOfWeek,
            ':period_number' => $periodNumber,
            ':note_content' => $content
        ]);
    }

    /**
     * Löscht eine Notiz, z.B. wenn der Inhalt geleert wird.
     */
    private function deleteNote(int $userId, int $year, int $calendarWeek, int $dayOfWeek, int $periodNumber): bool
    {
         $sql = "DELETE FROM student_notes
                  WHERE user_id = :user_id
                    AND `year` = :year
                    AND calendar_week = :calendar_week
                    AND day_of_week = :day_of_week
                    AND period_number = :period_number";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':user_id' => $userId,
            ':year' => $year,
            ':calendar_week' => $calendarWeek,
            ':day_of_week' => $dayOfWeek,
            ':period_number' => $periodNumber
        ]);
    }
}

