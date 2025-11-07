<?php
// app/Http/Controllers/AnnouncementController.php

// MODIFIZIERT:
// 1. FileUploadService importiert und im Konstruktor injiziert.
// 2. Die createAnnouncement()-Methode verwendet jetzt $this->fileUploadService->handleUpload().
// 3. Die manuelle Logik für Datei-Upload (MIME-Typ, Größe, Verschieben) wurde entfernt.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\UserRepository;
use App\Http\Traits\ApiHandlerTrait;
use App\Services\FileUploadService; // NEU: Service importieren
use Exception;
use PDO;
use Parsedown;
use App\Services\AuditLogger;

class AnnouncementController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private AnnouncementRepository $announcementRepo;
    private UserRepository $userRepo;
    private Parsedown $parsedown;
    private FileUploadService $fileUploadService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->announcementRepo = new AnnouncementRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(true);
        $this->fileUploadService = new FileUploadService(); // NEU
    }

    /**
     * API: Holt Ankündigungen basierend auf der Rolle des Benutzers. (GET)
     * (Unverändert)
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

            return [
                'json_response' => ['success' => true, 'data' => $announcements]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin']
        ]);
    }

    /**
     * API: Erstellt eine neue Ankündigung. (POST/FormData)
     * MODIFIZIERT: Nutzt jetzt FileUploadService.
     */
    public function createAnnouncement()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');

            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.", 400);
            }

            // --- Zielgruppen-Logik (unverändert) ---
            $targetRole = 'all';
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

            // --- NEU: Datei-Upload über Service ---
            $attachmentPath = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                if (!in_array($userRole, ['admin', 'planer'])) { 
                    throw new Exception("Nur Admins und Planer dürfen Dateien anhängen.", 403); 
                }
                
                // Erlaube gängige Dokumenten- und Bildtypen
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
                ];
                
                // Der Service wirft bei Fehlern (Größe, Typ, Verschieben) eine Exception
                $attachmentPath = $this->fileUploadService->handleUpload(
                    'attachment',
                    'announcements',
                    $allowedMimes,
                    5 * 1024 * 1024 // 5MB Limit
                );
            }
            // --- ENDE NEU ---

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
            'inputType' => 'form',
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }

    /**
     * API: Löscht eine Ankündigung. (POST/FormData)
     * MODIFIZIERT: Nutzt jetzt FileUploadService zum Löschen der Datei.
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
            
            if ($userRole === 'lehrer' && $announcement['user_id'] !== $userId) { 
                 throw new Exception("Sie sind nicht berechtigt, diese Ankündigung zu löschen.", 403); 
            }

            // NEU: Datei löschen, BEVOR der DB-Eintrag entfernt wird
            $filePathToDelete = $announcement['file_path'] ?? null;
            if ($filePathToDelete) {
                $this->fileUploadService->deleteFile($filePathToDelete);
                // Wir loggen Fehler beim Löschen, aber stoppen den Vorgang nicht
            }

            // DB-Eintrag löschen
            $success = $this->announcementRepo->deleteAnnouncement($announcementId);
            if (!$success) { 
                 throw new Exception("Fehler beim Löschen der Ankündigung aus der Datenbank.", 500); 
            }
            
            $logDetails = ['title' => $announcement['title'] ?? 'N/A'];
            
            return [
                 'json_response' => ['success' => true, 'message' => 'Ankündigung erfolgreich gelöscht.'],
                 'log_action' => 'delete_announcement',
                 'log_target_type' => 'announcement',
                 'log_target_id' => $announcementId,
                 'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }
}