<?php
// app/Http/Controllers/AnnouncementController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. getAnnouncements (GET) nutzt handleApiRequest('inputType' => 'get').
// 3. createAnnouncement (FormData) nutzt handleApiRequest('inputType' => 'form').
// 4. deleteAnnouncement (FormData) nutzt handleApiRequest('inputType' => 'form').
// 5. Alle manuellen try/catch, header(), json_encode() und Security-Checks
//    wurden aus den API-Methoden entfernt und in den Trait-Callback verschoben.
// 6. Parsedown-Import korrigiert (Backslash entfernt).

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\UserRepository;
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren
use Exception;
use PDO;
use Parsedown; // KORRIGIERT: Backslash entfernt
use App\Services\AuditLogger; // Import bleibt (wird vom Trait genutzt)

class AnnouncementController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

    private PDO $pdo;
    private AnnouncementRepository $announcementRepo;
    private UserRepository $userRepo;
    private Parsedown $parsedown;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->announcementRepo = new AnnouncementRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(true);
    }

    /**
     * API: Holt Ankündigungen basierend auf der Rolle des Benutzers. (GET)
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getAnnouncements()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $user = $this->userRepo->findById($userId);

            if (!$user) {
                throw new Exception("Benutzer nicht gefunden.", 404);
            }

            $classId = ($userRole === 'schueler' && isset($user['class_id'])) ? $user['class_id'] : null;
            $announcements = $this->announcementRepo->getVisibleAnnouncements($userRole, $classId);

            // Add author info, file URL, and convert content to HTML
            foreach ($announcements as &$announcement) {
                $author = $this->userRepo->findById($announcement['user_id']);
                $announcement['author_name'] = $author ? ($author['first_name'] . ' ' . $author['last_name']) : 'Unbekannt';

                $announcement['content_html'] = $this->parsedown->text($announcement['content'] ?? '');

                if (!empty($announcement['file_path'])) {
                    $announcement['file_url'] = rtrim(Database::getConfig()['base_url'], '/') . '/' . ltrim($announcement['file_path'], '/');
                } else {
                    $announcement['file_url'] = null;
                }
                $announcement['visibility'] = $announcement['is_global'] ? 'global' : 'class';
            }
            unset($announcement);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $announcements]
            ];

        }, [
            'inputType' => 'get',
            // HINWEIS: checkRole ist nötig, da /api/announcements direkt aufgerufen wird (nicht über index()).
            // Security::requireRole prüft intern auf requireLogin().
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin']
        ]);
    }

    /**
     * API: Erstellt eine neue Ankündigung. (POST/FormData)
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_POST.
     */
    public function createAnnouncement()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? ''); // Raw Markdown content

            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            // --- Determine Target Role and Class ID ---
            $targetRole = 'all'; // Default
            $targetClassId = null;
            if ($userRole === 'lehrer') {
                $targetClassId = filter_var($data['target_class_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$targetClassId) { throw new Exception("Lehrer müssen eine Klasse auswählen.", 400); }
                $targetRole = 'schueler';
            }
            elseif (in_array($userRole, ['admin', 'planer'])) {
                $isGlobal = isset($data['target_global']) && $data['target_global'] === '1';
                $isTeacher = isset($data['target_teacher']) && $data['target_teacher'] === '1';
                $isPlaner = isset($data['target_planer']) && $data['target_planer'] === '1';
                $selectedClassId = filter_var($data['target_class_id'] ?? null, FILTER_VALIDATE_INT);
                $selectedClassId = ($selectedClassId === false || $selectedClassId === 0) ? null : $selectedClassId;
                $checkedCount = ($isGlobal ? 1 : 0) + ($isTeacher ? 1 : 0) + ($isPlaner ? 1 : 0);

                if ($checkedCount > 1) { throw new Exception("Bitte nur eine Zielgruppen-Checkbox auswählen.", 400); }
                elseif ($checkedCount === 1) {
                    if ($isGlobal) $targetRole = 'all'; elseif ($isTeacher) $targetRole = 'lehrer'; elseif ($isPlaner) $targetRole = 'planer';
                    $targetClassId = null;
                } elseif ($selectedClassId !== null) {
                    $targetRole = 'schueler'; $targetClassId = $selectedClassId;
                } else { $targetRole = 'all'; $targetClassId = null; }
            } else { throw new Exception("Unbekannte Benutzerrolle.", 403); }

            // Handle File Upload if present
            $attachmentPath = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                if (!in_array($userRole, ['admin', 'planer'])) { throw new Exception("Nur Admins und Planer dürfen Dateien anhängen.", 403); }
                $uploadDir = dirname(__DIR__, 3) . '/public/uploads/announcements/';
                if (!is_dir($uploadDir)) { if (!mkdir($uploadDir, 0775, true)) { throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden.", 500); } }
                $fileName = uniqid('', true) . '_' . basename($_FILES['attachment']['name']); $targetFile = $uploadDir . $fileName;
                $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $fileType = mime_content_type($_FILES['attachment']['tmp_name']);
                if (!in_array($fileType, $allowedTypes)) { throw new Exception("Ungültiger Dateityp.", 400); }
                if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) { throw new Exception("Datei ist zu groß (max. 5MB).", 400); }
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) { $attachmentPath = 'uploads/announcements/' . $fileName; }
                else { throw new Exception("Fehler beim Hochladen der Datei.", 500); }
            }

            // Speichern
            $newId = $this->announcementRepo->createAnnouncement(
                $userId,
                $title,
                $content,
                $targetRole,
                $targetClassId,
                $attachmentPath
            );

            // Daten für Rückgabe holen
            $newAnnouncement = $this->announcementRepo->getAnnouncementById($newId);
            if ($newAnnouncement) {
                $newAnnouncement['content_html'] = $this->parsedown->text($newAnnouncement['content'] ?? '');
                $author = $this->userRepo->findById($newAnnouncement['user_id']);
                $newAnnouncement['author_name'] = $author ? ($author['first_name'] . ' ' . $author['last_name']) : 'Unbekannt';
                if (!empty($newAnnouncement['file_path'])) {
                     $newAnnouncement['file_url'] = rtrim(Database::getConfig()['base_url'], '/') . '/' . ltrim($newAnnouncement['file_path'], '/');
                } else {
                   $newAnnouncement['file_url'] = null;
                }
                $newAnnouncement['visibility'] = $newAnnouncement['is_global'] ? 'global' : 'class';
            }
            
            // Log-Details
            $logDetails = [
                'title' => $title, 
                'target_role' => $targetRole, 
                'target_class_id' => $targetClassId,
                'has_attachment' => !empty($attachmentPath)
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Ankündigung erfolgreich erstellt.', 'data' => $newAnnouncement],
                'log_action' => 'create_announcement',
                'log_target_type' => 'announcement',
                'log_target_id' => $newId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'form', // Wichtig: Nutzt $_POST und $_FILES
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }

    /**
     * API: Löscht eine Ankündigung. (POST/FormData)
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_POST.
     */
    public function deleteAnnouncement()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $announcementId = filter_var($data['announcement_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$announcementId) { throw new Exception("Ungültige Ankündigungs-ID.", 400); }
            $announcement = $this->announcementRepo->getAnnouncementById($announcementId);
            if (!$announcement) { throw new Exception("Ankündigung nicht gefunden.", 404); }
            
            // Berechtigungsprüfung
            if ($userRole === 'lehrer' && $announcement['user_id'] !== $userId) { 
                 throw new Exception("Sie sind nicht berechtigt, diese Ankündigung zu löschen.", 403); 
            }

            $success = $this->announcementRepo->deleteAnnouncement($announcementId);
            if (!$success) { 
                 throw new Exception("Fehler beim Löschen der Ankündigung.", 500); 
            }
            
            // Log-Details
            $logDetails = ['title' => $announcement['title'] ?? 'N/A'];
            
            // Rückgabe für Trait
            return [
                 'json_response' => ['success' => true, 'message' => 'Ankündigung erfolgreich gelöscht.'],
                 'log_action' => 'delete_announcement',
                 'log_target_type' => 'announcement',
                 'log_target_id' => $announcementId,
                 'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'form', // JS sendet FormData
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }
}