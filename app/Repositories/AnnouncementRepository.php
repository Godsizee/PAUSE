<?php
namespace App\Repositories;
use PDO;
use Exception; 
class AnnouncementRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function createAnnouncement(int $userId, string $title, string $content, string $targetRole, ?int $targetClassId, ?string $attachmentPath): int
    {
        $isGlobal = !($targetRole === 'schueler' && $targetClassId !== null);
        $dbClassId = ($targetRole === 'schueler' && $targetClassId !== null) ? $targetClassId : null;
        $sql = "INSERT INTO announcements (user_id, title, content, is_global, class_id, file_path, created_at) 
                VALUES (:user_id, :title, :content, :is_global, :class_id, :file_path, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':user_id' => $userId,
            ':title' => $title,
            ':content' => $content,
            ':is_global' => $isGlobal ? 1 : 0,
            ':class_id' => $dbClassId,
            ':file_path' => $attachmentPath 
        ]);
        if (!$success) {
            error_log("Announcement creation failed: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Fehler beim Erstellen der Ankündigung.");
        }
        return (int)$this->pdo->lastInsertId();
    }
    public function getVisibleAnnouncements(string $userRole, ?int $classId): array
    {
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as author_name, c.class_name as target_class_name
                FROM announcements a
                JOIN users u ON a.user_id = u.user_id
                LEFT JOIN classes c ON a.class_id = c.class_id
                WHERE (
                    a.is_global = 1"; 
        $params = [];
        if ($userRole === 'schueler' && $classId !== null) {
            $sql .= " OR (a.is_global = 0 AND a.class_id = :class_id)";
            $params[':class_id'] = $classId;
        }
        $sql .= " ) ORDER BY a.created_at DESC LIMIT 20";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getAllAnnouncementsWithDetails(): array
    {
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as author_name, c.class_name as target_class_name
                FROM announcements a
                JOIN users u ON a.user_id = u.user_id
                LEFT JOIN classes c ON a.class_id = c.class_id
                ORDER BY a.created_at DESC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getAnnouncementById(int $announcementId): array|false
    {
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as author_name, c.class_name as target_class_name
                FROM announcements a
                JOIN users u ON a.user_id = u.user_id
                LEFT JOIN classes c ON a.class_id = c.class_id
                WHERE a.announcement_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $announcementId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function deleteAnnouncement(int $announcementId): bool
    {
        $announcement = $this->getAnnouncementById($announcementId);
        $sql = "DELETE FROM announcements WHERE announcement_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([':id' => $announcementId]);
        if ($success && $announcement && !empty($announcement['file_path'])) {
             $filePath = dirname(__DIR__, 2) . '/public/' . $announcement['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        return $success;
    }
}