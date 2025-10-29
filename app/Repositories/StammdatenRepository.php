<?php
// app/Repositories/StammdatenRepository.php
namespace App\Repositories;

use PDO;
use Exception; // Hinzugefügt für Zähl-Methoden

class StammdatenRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // --- Subject Methods ---
    public function getSubjects(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM subjects ORDER BY subject_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function createSubject(string $name, string $shortcut): int {
        $sql = "INSERT INTO subjects (subject_name, subject_shortcut) VALUES (:name, :shortcut)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => $name, ':shortcut' => $shortcut]);
        return (int)$this->pdo->lastInsertId();
    }
    public function updateSubject(int $id, string $name, string $shortcut): bool {
        $sql = "UPDATE subjects SET subject_name = :name, subject_shortcut = :shortcut WHERE subject_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':name' => $name, ':shortcut' => $shortcut]);
    }
    public function deleteSubject(int $id): bool {
        $sql = "DELETE FROM subjects WHERE subject_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
    /** NEU: Zählt die Anzahl der Fächer */
    public function countSubjects(): int {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM subjects");
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            error_log("Fehler beim Zählen der Fächer: " . $e->getMessage());
            return 0;
        }
    }

    // --- Rooms Methods ---
    public function getRooms(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM rooms ORDER BY room_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function createRoom(string $name): int {
        $sql = "INSERT INTO rooms (room_name) VALUES (:name)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':name' => $name]);
        return (int)$this->pdo->lastInsertId();
    }
    public function updateRoom(int $id, string $name): bool {
        $sql = "UPDATE rooms SET room_name = :name WHERE room_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':name' => $name]);
    }
    public function deleteRoom(int $id): bool {
        $sql = "DELETE FROM rooms WHERE room_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
    /** NEU: Zählt die Anzahl der Räume */
    public function countRooms(): int {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM rooms");
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            error_log("Fehler beim Zählen der Räume: " . $e->getMessage());
            return 0;
        }
    }

    // --- Teachers Methods ---
    public function getTeachers(): array {
        $stmt = $this->pdo->prepare("SELECT * FROM teachers ORDER BY last_name, first_name ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function createTeacher(array $data): int {
        $sql = "INSERT INTO teachers (teacher_shortcut, first_name, last_name, email) VALUES (:shortcut, :first_name, :last_name, :email)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }
    public function updateTeacher(int $id, array $data): bool {
        $sql = "UPDATE teachers SET teacher_shortcut = :shortcut, first_name = :first_name, last_name = :last_name, email = :email WHERE teacher_id = :id";
        $data['id'] = $id;
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }
    public function deleteTeacher(int $id): bool {
        $sql = "DELETE FROM teachers WHERE teacher_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
    /** NEU: Zählt die Anzahl der Lehrer */
    public function countTeachers(): int {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM teachers");
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            error_log("Fehler beim Zählen der Lehrer: " . $e->getMessage());
            return 0;
        }
    }

    // --- Classes Methods (ANGEPASST) ---
    public function getClasses(): array {
        $sql = "SELECT c.class_id, c.class_name, c.class_teacher_id, CONCAT_WS(' ', t.first_name, t.last_name) as teacher_name
                FROM classes c
                LEFT JOIN teachers t ON c.class_teacher_id = t.teacher_id
                ORDER BY c.class_id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createClass(int $id, string $name, ?int $teacherId): bool {
        // Prüfen, ob die ID bereits existiert
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM classes WHERE class_id = :id");
        $stmtCheck->execute([':id' => $id]);
        if ($stmtCheck->fetchColumn() > 0) {
            throw new \Exception("Die Klassen-ID '{$id}' ist bereits vergeben.");
        }

        $sql = "INSERT INTO classes (class_id, class_name, class_teacher_id) VALUES (:id, :name, :teacher_id)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':name' => $name, ':teacher_id' => $teacherId]);
    }

    public function updateClass(int $id, string $name, ?int $teacherId): bool {
        // Die ID (Primärschlüssel) selbst wird hier nicht geändert.
        $sql = "UPDATE classes SET class_name = :name, class_teacher_id = :teacher_id WHERE class_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':name' => $name, ':teacher_id' => $teacherId]);
    }

    public function deleteClass(int $id): bool {
        $sql = "DELETE FROM classes WHERE class_id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }
    /** NEU: Zählt die Anzahl der Klassen */
    public function countClasses(): int {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM classes");
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Exception $e) {
            error_log("Fehler beim Zählen der Klassen: " . $e->getMessage());
            return 0;
        }
    }
}

