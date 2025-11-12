<?php
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
    public function saveAttendance(int $teacherUserId, int $classId, string $date, int $periodNumber, array $studentsStatus): bool
    {
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
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}