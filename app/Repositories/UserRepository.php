<?php
namespace App\Repositories;
use PDO;
use Exception;
class UserRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function findByUsernameOrEmail(string $identifier): ?array
    {
        $sql = "SELECT user_id, username, password_hash, role, ical_token, is_community_banned
                FROM users
                WHERE username = :identifier OR email = :identifier";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':identifier' => $identifier]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    public function getAll(): array
    {
        $sql = "SELECT u.user_id, u.username, u.email, u.role, u.first_name, u.last_name, u.birth_date, u.class_id, u.teacher_id, u.ical_token, u.is_community_banned, c.class_name, CONCAT(t.first_name, ' ', t.last_name) as teacher_name
                FROM users u
                LEFT JOIN classes c ON u.class_id = c.class_id
                LEFT JOIN teachers t ON u.teacher_id = t.teacher_id
                ORDER BY u.last_name, u.first_name ASC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function findById(int $userId): ?array
    {
        $sql = "SELECT * FROM users WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    public function findClassByUserId(int $userId): ?array
    {
        $sql = "SELECT c.class_id, c.class_name
                FROM users u
                JOIN classes c ON u.class_id = c.class_id
                WHERE u.user_id = :user_id AND u.role = 'schueler'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        $classData = $stmt->fetch(PDO::FETCH_ASSOC);
        return $classData ?: null;
    }
    public function getStudentsByClassId(int $classId): array
    {
        $sql = "SELECT user_id, first_name, last_name 
                FROM users 
                WHERE role = 'schueler' 
                  AND class_id = :class_id 
                ORDER BY last_name ASC, first_name ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':class_id' => $classId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function create(array $data): int
    {
        if (empty($data['username']) || empty($data['email']) || empty($data['password']) || empty($data['role']) || empty($data['first_name']) || empty($data['last_name'])) {
            throw new Exception("Alle Felder mit * sind erforderlich.");
        }
        if ($this->findByUsernameOrEmail($data['username'])) {
            throw new Exception("Benutzername ist bereits vergeben.");
        }
        if ($this->findByUsernameOrEmail($data['email'])) {
            throw new Exception("E-Mail ist bereits vergeben.");
        }
        $icalToken = bin2hex(random_bytes(32));
        $sql = "INSERT INTO users (username, email, password_hash, role, first_name, last_name, birth_date, class_id, teacher_id, ical_token, is_community_banned)
                VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, :birth_date, :class_id, :teacher_id, :ical_token, :is_community_banned)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':role' => $data['role'],
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birth_date' => empty($data['birth_date']) ? null : $data['birth_date'],
            ':class_id' => ($data['role'] === 'schueler' && !empty($data['class_id'])) ? $data['class_id'] : null,
            ':teacher_id' => ($data['role'] === 'lehrer' && !empty($data['teacher_id'])) ? $data['teacher_id'] : null,
            ':ical_token' => $icalToken, 
            ':is_community_banned' => ($data['role'] === 'schueler' ? (isset($data['is_community_banned']) ? 1 : 0) : 0)
        ]);
        return (int)$this->pdo->lastInsertId();
    }
    public function update(int $userId, array $data): bool
    {
        $sqlCheck = "SELECT user_id FROM users WHERE (username = :username OR email = :email) AND user_id != :user_id";
        $stmtCheck = $this->pdo->prepare($sqlCheck);
        $stmtCheck->execute([':username' => $data['username'], ':email' => $data['email'], ':user_id' => $userId]);
        if ($stmtCheck->fetch()) {
            throw new Exception("Benutzername oder E-Mail ist bereits von einem anderen Benutzer vergeben.");
        }
        $sql = "UPDATE users SET
                        username = :username,
                        email = :email,
                        role = :role,
                        first_name = :first_name,
                        last_name = :last_name,
                        birth_date = :birth_date,
                        class_id = :class_id,
                        teacher_id = :teacher_id,
                        is_community_banned = :is_community_banned"; 
        $params = [
            ':user_id' => $userId,
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':role' => $data['role'],
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':birth_date' => empty($data['birth_date']) ? null : $data['birth_date'],
            ':class_id' => ($data['role'] === 'schueler' && !empty($data['class_id'])) ? $data['class_id'] : null,
            ':teacher_id' => ($data['role'] === 'lehrer' && !empty($data['teacher_id'])) ? $data['teacher_id'] : null,
            ':is_community_banned' => ($data['role'] === 'schueler' ? (isset($data['is_community_banned']) ? 1 : 0) : 0)
        ];
        if (!empty($data['password'])) {
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $sql .= " WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    public function delete(int $userId): bool
    {
        $sql = "DELETE FROM users WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':user_id' => $userId]);
    }
    public function getAvailableRoles(): array
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($columnInfo && isset($columnInfo['Type'])) {
            preg_match_all("/'([^']+)'/", $columnInfo['Type'], $matches);
            if (!empty($matches[1])) {
                return $matches[1]; 
            }
        }
        return ['schueler', 'lehrer', 'planer', 'admin'];
    }
    public function findByIcalToken(string $token): ?array
    {
        $sql = "SELECT * FROM users WHERE ical_token = :token";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    public function generateOrGetIcalToken(int $userId): ?string
    {
        $user = $this->findById($userId);
        if (!$user) return null;
        if (!empty($user['ical_token'])) {
            return $user['ical_token'];
        }
        $newToken = bin2hex(random_bytes(32));
        $sql = "UPDATE users SET ical_token = :token WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        if ($stmt->execute([':token' => $newToken, ':user_id' => $userId])) {
            return $newToken;
        }
        error_log("Failed to update iCal token for user ID: " . $userId); 
        return null; 
    }
    public function findUserByTeacherId(int $teacherId): ?array
    {
        $sql = "SELECT * FROM users WHERE teacher_id = :teacher_id AND role = 'lehrer' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_id' => $teacherId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }
    public function importFromCSV(string $tmpFilePath, array $validationData): array
    {
        $successCount = 0;
        $errorMessages = [];
        $requiredHeaders = ['username', 'email', 'password', 'role', 'first_name', 'last_name'];
        $optionalHeaders = ['birth_date', 'class_id', 'teacher_id', 'is_community_banned'];
        $fileHandle = fopen($tmpFilePath, 'r');
        if ($fileHandle === false) {
            throw new Exception("Datei konnte nicht zum Lesen geöffnet werden.");
        }
        $this->pdo->beginTransaction();
        try {
            $headers = fgetcsv($fileHandle);
            if ($headers === false) {
                throw new Exception("CSV-Datei ist leer oder konnte nicht gelesen werden.");
            }
            $headers = array_map('trim', $headers); 
            $colMap = [];
            foreach ($requiredHeaders as $header) {
                $index = array_search($header, $headers);
                if ($index === false) {
                    throw new Exception("Fehlende erforderliche Spalte in der CSV-Vorlage: '{$header}'.");
                }
                $colMap[$header] = $index;
            }
            foreach ($optionalHeaders as $header) {
                $colMap[$header] = array_search($header, $headers); 
            }
            $insertSql = "INSERT INTO users (username, email, password_hash, role, first_name, last_name, birth_date, class_id, teacher_id, ical_token, is_community_banned)
                                VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, :birth_date, :class_id, :teacher_id, :ical_token, :is_community_banned)";
            $stmt = $this->pdo->prepare($insertSql);
            $lineNumber = 1; 
            while (($row = fgetcsv($fileHandle)) !== false) {
                $lineNumber++;
                $row = array_map('trim', $row);
                $userData = [];
                $userData['username'] = $row[$colMap['username']] ?? null;
                $userData['email'] = $row[$colMap['email']] ?? null;
                $userData['password'] = $row[$colMap['password']] ?? null;
                $userData['role'] = $row[$colMap['role']] ?? null;
                $userData['first_name'] = $row[$colMap['first_name']] ?? null;
                $userData['last_name'] = $row[$colMap['last_name']] ?? null;
                $userData['birth_date'] = ($colMap['birth_date'] !== false && !empty($row[$colMap['birth_date']])) ? $row[$colMap['birth_date']] : null;
                $userData['class_id'] = ($colMap['class_id'] !== false && !empty($row[$colMap['class_id']])) ? $row[$colMap['class_id']] : null;
                $userData['teacher_id'] = ($colMap['teacher_id'] !== false && !empty($row[$colMap['teacher_id']])) ? $row[$colMap['teacher_id']] : null;
                $userData['is_community_banned'] = ($colMap['is_community_banned'] !== false && !empty($row[$colMap['is_community_banned']])) ? $row[$colMap['is_community_banned']] : '0';
                if (empty($userData['username']) || empty($userData['email']) || empty($userData['password']) || empty($userData['role']) || empty($userData['first_name']) || empty($userData['last_name'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. Es fehlen erforderliche Felder (z.B. username, email, password, role, first_name, last_name).";
                    continue;
                }
                if ($this->findByUsernameOrEmail($userData['username'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. Benutzername '{$userData['username']}' ist bereits vergeben.";
                    continue;
                }
                if ($this->findByUsernameOrEmail($userData['email'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. E-Mail '{$userData['email']}' ist bereits vergeben.";
                    continue;
                }
                if (!in_array($userData['role'], $validationData['roles'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. Ungültige Rolle '{$userData['role']}'.";
                    continue;
                }
                if ($userData['role'] === 'schueler' && !empty($userData['class_id']) && !in_array($userData['class_id'], $validationData['class_ids'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. Klassen-ID '{$userData['class_id']}' existiert nicht.";
                    continue;
                }
                if ($userData['role'] === 'lehrer' && !empty($userData['teacher_id']) && !in_array($userData['teacher_id'], $validationData['teacher_ids'])) {
                    $errorMessages[] = "Zeile {$lineNumber}: Übersprungen. Lehrer-ID '{$userData['teacher_id']}' existiert nicht.";
                    continue;
                }
                $classId = ($userData['role'] === 'schueler') ? $userData['class_id'] : null;
                $teacherId = ($userData['role'] === 'lehrer') ? $userData['teacher_id'] : null;
                if ($classId === '0') $classId = null;
                if ($teacherId === '0') $teacherId = null;
                $isBanned = ($userData['role'] === 'schueler' && ($userData['is_community_banned'] === '1' || strtolower($userData['is_community_banned']) === 'true')) ? 1 : 0;
                $params = [
                    ':username' => $userData['username'],
                    ':email' => $userData['email'],
                    ':password_hash' => password_hash($userData['password'], PASSWORD_DEFAULT),
                    ':role' => $userData['role'],
                    ':first_name' => $userData['first_name'],
                    ':last_name' => $userData['last_name'],
                    ':birth_date' => empty($userData['birth_date']) ? null : $userData['birth_date'],
                    ':class_id' => $classId,
                    ':teacher_id' => $teacherId,
                    ':ical_token' => bin2hex(random_bytes(32)), 
                    ':is_community_banned' => $isBanned 
                ];
                if (!$stmt->execute($params)) {
                    $errorMessages[] = "Zeile {$lineNumber}: Technischer Fehler beim Einfügen von '{$userData['username']}'.";
                } else {
                    $successCount++;
                }
            }
            if (!empty($errorMessages)) {
                $this->pdo->rollBack();
                fclose($fileHandle);
                $errorMessages[] = "Transaktion abgebrochen. Keine Benutzer wurden importiert.";
                return ['successCount' => 0, 'errors' => array_slice($errorMessages, 0, 50)];
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            fclose($fileHandle);
            throw new Exception("Fehler beim Verarbeiten der CSV-Datei: " . $e->getMessage());
        }
        fclose($fileHandle);
        return ['successCount' => $successCount, 'errors' => $errorMessages];
    }
    public function countUsersByRole(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {
            error_log("Fehler beim Zählen der Benutzer nach Rolle: " . $e->getMessage());
            return [];
        }
    }
}