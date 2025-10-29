<?php
// app/Http/Controllers/AnnouncementController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\UserRepository;
use Exception;
use PDO;
use \Parsedown; // KORREKTUR: Parsedown aus dem globalen Namespace importieren
use App\Services\AuditLogger; 

class AnnouncementController
{
    private PDO $pdo;
    private AnnouncementRepository $announcementRepo;
    private UserRepository $userRepo;
    private Parsedown $parsedown; // KORREKTUR: Typehint kann jetzt ohne Backslash sein

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->announcementRepo = new AnnouncementRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->parsedown = new Parsedown(); // KORREKTUR: Kann jetzt direkt verwendet werden
        $this->parsedown->setSafeMode(true); 
    }

     // --- API Helper ---
     /**
     * Helper to wrap common API request logic (auth, CSRF, response type, error handling)
     * @param callable $callback The actual logic to execute
     * @param string $actionName Der Name der Aktion für das Audit-Log (z.B. 'create_announcement')
     * @param string $targetType Der Typ des Ziels (z.B. 'announcement')
     * @param bool $isGetRequest Ob es sich um eine GET-Anfrage handelt (kein CSRF-Check)
     */
    private function handleApiRequest(callable $callback, string $actionName = '', string $targetType = '', bool $isGetRequest = false): void
    {
        header('Content-Type: application/json');
        try {
            if (!$isGetRequest) {
                 Security::verifyCsrfToken();
            }
            
            // Führe die eigentliche Aktion aus
            $result = $callback(); // Callback gibt jetzt ggf. Daten zurück

            // Protokollierung bei Erfolg (nur wenn actionName gesetzt ist)
            if ($actionName) {
                AuditLogger::log(
                    $actionName,
                    $targetType,
                    $result['target_id'] ?? null, // ID des erstellten/bearbeiteten Objekts
                    $result['details'] ?? null    // Details (z.B. Name)
                );
            }

        } catch (Exception $e) {
            $statusCode = 400; // Default Bad Request
            if (str_contains($e->getMessage(), 'CSRF')) {
                $statusCode = 403; // Forbidden
            } elseif (str_contains($e->getMessage(), 'berechtigt')) {
                $statusCode = 403; // Forbidden
            }
            
            http_response_code($statusCode);
            error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString()); // Log detailed error
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }


    /**
     * API: Holt Ankündigungen basierend auf der Rolle des Benutzers.
     * (GET request - no CSRF needed)
     */
    public function getAnnouncements()
    {
        Security::requireLogin();
        header('Content-Type: application/json'); // Ensure JSON header
        try {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $user = $this->userRepo->findById($userId);

            if (!$user) {
                throw new Exception("Benutzer nicht gefunden.");
            }

            $classId = ($userRole === 'schueler' && isset($user['class_id'])) ? $user['class_id'] : null;
            $announcements = $this->announcementRepo->getVisibleAnnouncements($userRole, $classId);

             // Add author info, file URL, and convert content to HTML
             foreach ($announcements as &$announcement) {
                 $author = $this->userRepo->findById($announcement['user_id']);
                 $announcement['author_name'] = $author ? ($author['first_name'] . ' ' . $author['last_name']) : 'Unbekannt';

                 // Convert Markdown content to safe HTML
                 $announcement['content_html'] = $this->parsedown->text($announcement['content'] ?? '');

                 if (!empty($announcement['file_path'])) {
                     $announcement['file_url'] = rtrim(Database::getConfig()['base_url'], '/') . '/' . ltrim($announcement['file_path'], '/');
                 } else {
                    $announcement['file_url'] = null;
                 }
                 $announcement['visibility'] = $announcement['is_global'] ? 'global' : 'class';
             }
             unset($announcement);


            echo json_encode(['success' => true, 'data' => $announcements], JSON_THROW_ON_ERROR);

        } catch (Exception $e) {
            error_log("Error in getAnnouncements API: " . $e->getMessage()); // Log error
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Ankündigungen.'], JSON_THROW_ON_ERROR); // Generic message
        }
        exit(); // Make sure script exits
    }

    /**
     * API: Erstellt eine neue Ankündigung.
     * (POST request)
     */
    public function createAnnouncement()
    {
        Security::requireRole(['admin', 'planer', 'lehrer']);
        
        $this->handleApiRequest(function() {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? ''); // Raw Markdown content

            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.");
            }

            // --- Determine Target Role and Class ID ---
            $targetRole = 'all'; // Default
            $targetClassId = null;
             if ($userRole === 'lehrer') {
                 $targetClassId = filter_input(INPUT_POST, 'target_class_id', FILTER_VALIDATE_INT);
                 if (!$targetClassId) { throw new Exception("Lehrer müssen eine Klasse auswählen."); }
                 $targetRole = 'schueler';
             }
             elseif (in_array($userRole, ['admin', 'planer'])) {
                 $isGlobal = isset($_POST['target_global']) && $_POST['target_global'] === '1';
                 $isTeacher = isset($_POST['target_teacher']) && $_POST['target_teacher'] === '1';
                 $isPlaner = isset($_POST['target_planer']) && $_POST['target_planer'] === '1';
                 $selectedClassId = filter_input(INPUT_POST, 'target_class_id', FILTER_VALIDATE_INT);
                 $selectedClassId = ($selectedClassId === false || $selectedClassId === 0) ? null : $selectedClassId;
                 $checkedCount = ($isGlobal ? 1 : 0) + ($isTeacher ? 1 : 0) + ($isPlaner ? 1 : 0);

                 if ($checkedCount > 1) { throw new Exception("Bitte nur eine Zielgruppen-Checkbox auswählen."); }
                 elseif ($checkedCount === 1) {
                     if ($isGlobal) $targetRole = 'all'; elseif ($isTeacher) $targetRole = 'lehrer'; elseif ($isPlaner) $targetRole = 'planer';
                     $targetClassId = null;
                 } elseif ($selectedClassId !== null) {
                     $targetRole = 'schueler'; $targetClassId = $selectedClassId;
                 } else { $targetRole = 'all'; $targetClassId = null; } // Default to 'all' if nothing selected
             } else { throw new Exception("Unbekannte Benutzerrolle."); }


            // Handle File Upload if present
            $attachmentPath = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                 if (!in_array($userRole, ['admin', 'planer'])) { throw new Exception("Nur Admins und Planer dürfen Dateien anhängen."); }
                 $uploadDir = dirname(__DIR__, 3) . '/public/uploads/announcements/';
                 if (!is_dir($uploadDir)) { if (!mkdir($uploadDir, 0775, true)) { throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden."); } }
                 $fileName = uniqid('', true) . '_' . basename($_FILES['attachment']['name']); $targetFile = $uploadDir . $fileName;
                 $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                 $fileType = mime_content_type($_FILES['attachment']['tmp_name']);
                 if (!in_array($fileType, $allowedTypes)) { throw new Exception("Ungültiger Dateityp."); }
                 if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) { throw new Exception("Datei ist zu groß (max. 5MB)."); }
                 if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) { $attachmentPath = 'uploads/announcements/' . $fileName; }
                 else { throw new Exception("Fehler beim Hochladen der Datei."); }
            }


            // Pass raw Markdown content to repository
            $newId = $this->announcementRepo->createAnnouncement(
                $userId,
                $title,
                $content, // Pass raw Markdown
                $targetRole,
                $targetClassId,
                $attachmentPath
            );

            // Fetch the newly created announcement
            $newAnnouncement = $this->announcementRepo->getAnnouncementById($newId);
            if ($newAnnouncement) {
                 // Convert content to HTML for the response
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

            echo json_encode(['success' => true, 'message' => 'Ankündigung erfolgreich erstellt.', 'data' => $newAnnouncement], JSON_THROW_ON_ERROR);
            
            // Rückgabe für Audit-Log
            return [
                'target_id' => $newId,
                'details' => [
                    'title' => $title, 
                    'target_role' => $targetRole, 
                    'target_class_id' => $targetClassId,
                    'has_attachment' => !empty($attachmentPath)
                ]
            ];

        }, 'create_announcement', 'announcement');
    }

     /**
      * API: Löscht eine Ankündigung.
      * (POST request)
      */
     public function deleteAnnouncement()
     {
         Security::requireRole(['admin', 'planer', 'lehrer']);
         
         $this->handleApiRequest(function() {
              $userId = $_SESSION['user_id'];
              $userRole = $_SESSION['user_role'];
              $announcementId = filter_input(INPUT_POST, 'announcement_id', FILTER_VALIDATE_INT);

              if (!$announcementId) { throw new Exception("Ungültige Ankündigungs-ID."); }
              $announcement = $this->announcementRepo->getAnnouncementById($announcementId);
              if (!$announcement) { throw new Exception("Ankündigung nicht gefunden."); }
              
              // Berechtigungsprüfung
              if ($userRole === 'lehrer' && $announcement['user_id'] !== $userId) { 
                   throw new Exception("Sie sind nicht berechtigt, diese Ankündigung zu löschen."); 
              }

              $success = $this->announcementRepo->deleteAnnouncement($announcementId);

              if ($success) { 
                   echo json_encode(['success' => true, 'message' => 'Ankündigung erfolgreich gelöscht.'], JSON_THROW_ON_ERROR); 
              }
              else { 
                   throw new Exception("Fehler beim Löschen der Ankündigung."); 
              }
              
              // Rückgabe für Audit-Log
              return [
                   'target_id' => $announcementId,
                   'details' => ['title' => $announcement['title'] ?? 'N/A']
              ];

         }, 'delete_announcement', 'announcement');
     }
}