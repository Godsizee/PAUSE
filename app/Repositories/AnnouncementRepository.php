<?php
// app/Repositories/AnnouncementRepository.php
namespace App\Repositories;

use PDO;
use Exception; // Added for potential errors

class AnnouncementRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Erstellt eine neue Ankündigung in der Datenbank.
     * Nutzt is_global und class_id basierend auf der Logik im Controller.
     *
     * @param int $userId ID des Autors
     * @param string $title Titel
     * @param string $content Inhalt
     * @param string $targetRole Zielgruppe ('all', 'schueler', 'lehrer', 'planer') - Wird jetzt in is_global/class_id übersetzt
     * @param ?int $targetClassId Klassen-ID (nur wenn targetRole 'schueler')
     * @param ?string $attachmentPath Pfad zur angehängten Datei (relativ zum public-Ordner)
     * @return int Die ID der neu erstellten Ankündigung.
     * @throws Exception
     */
    public function createAnnouncement(int $userId, string $title, string $content, string $targetRole, ?int $targetClassId, ?string $attachmentPath): int
    {
        // *** Convert targetRole/targetClassId to is_global/class_id ***
        // 'schueler' with a class ID means it's class-specific (is_global = 0)
        // 'all', 'lehrer', 'planer', or 'schueler' without a class ID means it's global (is_global = 1)
        $isGlobal = !($targetRole === 'schueler' && $targetClassId !== null);
        $dbClassId = ($targetRole === 'schueler' && $targetClassId !== null) ? $targetClassId : null;

        // *** Use is_global and class_id in SQL ***
        $sql = "INSERT INTO announcements (user_id, title, content, is_global, class_id, file_path, created_at) /* Corrected column name file_path */
                VALUES (:user_id, :title, :content, :is_global, :class_id, :file_path, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':user_id' => $userId,
            ':title' => $title,
            ':content' => $content,
            ':is_global' => $isGlobal ? 1 : 0,
            ':class_id' => $dbClassId,
            ':file_path' => $attachmentPath // Corrected parameter name
        ]);

        if (!$success) {
            // Log detailed error
            error_log("Announcement creation failed: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Fehler beim Erstellen der Ankündigung.");
        }
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Holt Ankündigungen, die für einen bestimmten Benutzer sichtbar sind.
     * Berücksichtigt Rolle und ggf. Klassenzugehörigkeit.
     *
     * @param string $userRole Rolle des aktuellen Benutzers
     * @param ?int $classId Klassen-ID des Schülers (falls zutreffend)
     * @return array Array von Ankündigungen.
     */
    public function getVisibleAnnouncements(string $userRole, ?int $classId): array
    {
        // *** Uses is_global and class_id ***
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as author_name, c.class_name as target_class_name
                FROM announcements a
                JOIN users u ON a.user_id = u.user_id
                LEFT JOIN classes c ON a.class_id = c.class_id
                WHERE (
                    a.is_global = 1"; // Global announcements are always visible (covers all, lehrer, planer types)

        $params = [];

        // Add condition for students to see their class-specific announcements
        if ($userRole === 'schueler' && $classId !== null) {
            $sql .= " OR (a.is_global = 0 AND a.class_id = :class_id)";
            $params[':class_id'] = $classId;
        }

        // Close the main WHERE parenthesis
        $sql .= " ) ORDER BY a.created_at DESC LIMIT 20";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Holt alle Ankündigungen mit zusätzlichen Details (Autor, Klasse).
     * Wird für die Admin-Ansicht verwendet.
     * @return array Array aller Ankündigungen.
     */
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


    /**
     * Holt eine einzelne Ankündigung anhand ihrer ID.
     * @param int $announcementId
     * @return array|false Die Ankündigungsdaten oder false, wenn nicht gefunden.
     */
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

    /**
     * Löscht eine Ankündigung anhand ihrer ID.
     * @param int $announcementId
     * @return bool True bei Erfolg, False bei Misserfolg.
     */
    public function deleteAnnouncement(int $announcementId): bool
    {
        $announcement = $this->getAnnouncementById($announcementId);

        $sql = "DELETE FROM announcements WHERE announcement_id = :id";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([':id' => $announcementId]);

        // If deletion was successful and there was an attachment, try to delete the file
        if ($success && $announcement && !empty($announcement['file_path'])) {
             $filePath = dirname(__DIR__, 2) . '/public/' . $announcement['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        return $success;
    }

     // Update method (Placeholder - needs implementation)
     /*
     public function updateAnnouncement(int $announcementId, int $userId, string $title, string $content, string $targetRole, ?int $targetClassId, ?string $attachmentPath, bool $removeAttachment): bool {
         // Determine is_global and dbClassId based on targetRole/targetClassId
         $isGlobal = !($targetRole === 'schueler' && $targetClassId !== null);
         $dbClassId = ($targetRole === 'schueler' && $targetClassId !== null) ? $targetClassId : null;

         // Fetch current announcement to handle file deletion if requested or replaced
         $current = $this->getAnnouncementById($announcementId);
         $currentFilePath = $current['file_path'] ?? null;
         $newFilePath = $attachmentPath; // If a new file was uploaded
         $finalFilePath = $newFilePath; // Assume new file replaces old by default

         if ($removeAttachment && !$newFilePath && $currentFilePath) {
             // Delete existing file, set path to NULL
             $filePathToDelete = dirname(__DIR__, 2) . '/public/' . $currentFilePath;
              if (file_exists($filePathToDelete)) {
                  @unlink($filePathToDelete);
              }
             $finalFilePath = null;
         } elseif ($newFilePath && $currentFilePath && $newFilePath !== $currentFilePath) {
             // New file replaces old one, delete the old one
              $filePathToDelete = dirname(__DIR__, 2) . '/public/' . $currentFilePath;
              if (file_exists($filePathToDelete)) {
                  @unlink($filePathToDelete);
              }
             $finalFilePath = $newFilePath;
         } elseif (!$newFilePath && !$removeAttachment) {
              // No new file, don't remove existing -> keep current path
              $finalFilePath = $currentFilePath;
         }
         // If !$newFilePath and $removeAttachment, finalFilePath is already correctly set to null above.


         $sql = "UPDATE announcements SET
                     title = :title,
                     content = :content,
                     is_global = :is_global,
                     class_id = :class_id,
                     file_path = :file_path
                     -- Optionally update user_id or created_at? Probably not.
                 WHERE announcement_id = :id";

         $stmt = $this->pdo->prepare($sql);
         return $stmt->execute([
             ':title' => $title,
             ':content' => $content,
             ':is_global' => $isGlobal ? 1 : 0,
             ':class_id' => $dbClassId,
             ':file_path' => $finalFilePath,
             ':id' => $announcementId
         ]);
     }
     */
}