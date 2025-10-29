<?php
// app/Repositories/AttendanceRepository.php

namespace App\Repositories;

use PDO;
use Exception;
use PDOException;

class AttendanceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Speichert oder aktualisiert einen Batch von Anwesenheitsdaten.
     *
     * @param int $teacherUserId Der Lehrer, der die Daten speichert
     * @param int $classId
     * @param string $date (Y-m-d)
     * @param int $periodNumber
     * @param array $studentsStatus Array von ['student_id' => X, 'status' => '...']
     * @return bool
     * @throws Exception
     */
    public function saveAttendance(int $teacherUserId, int $classId, string $date, int $periodNumber, array $studentsStatus): bool
    {
        // SQL mit ON DUPLICATE KEY UPDATE, um Atomarität zu gewährleisten
        $sql = "INSERT INTO attendance_logs (date, period_number, class_id, student_user_id, teacher_user_id, status)
                VALUES (:date, :period_number, :class_id, :student_user_id, :teacher_user_id, :status)
                ON DUPLICATE KEY UPDATE 
                    teacher_user_id = VALUES(teacher_user_id), 
                    status = VALUES(status)";
        
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare($sql);

            foreach ($studentsStatus as $student) {
                if (!isset($student['student_id']) || !isset($student['status'])) {
                    throw new Exception("Ungültige Studentendaten im Batch.");
                }
                
                $stmt->execute([
                    ':date' => $date,
                    ':period_number' => $periodNumber,
                    ':class_id' => $classId,
                    ':student_user_id' => $student['student_id'],
                    ':teacher_user_id' => $teacherUserId,
                    ':status' => $student['status']
                ]);
            }
            
            return $this->pdo->commit();

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Fehler beim Speichern der Anwesenheit: " . $e->getMessage());
            throw new Exception("Fehler beim Speichern der Anwesenheit: " . $e->getMessage());
        }
    }

    /**
     * Ruft die bereits erfasste Anwesenheit für eine bestimmte Stunde ab.
     *
     * @param int $classId
     * @param string $date
     * @param int $periodNumber
     * @return array Assoziatives Array [student_user_id => status]
     */
    public function getAttendance(int $classId, string $date, int $periodNumber): array
    {
        $sql = "SELECT student_user_id, status 
                FROM attendance_logs
                WHERE class_id = :class_id 
                  AND date = :date 
                  AND period_number = :period_number";
                  
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':class_id' => $classId,
            ':date' => $date,
            ':period_number' => $periodNumber
        ]);
        
        // Gibt ein Array zurück, z.B. [15 => 'anwesend', 16 => 'abwesend']
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}