<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\UserRepository;
use Exception;
use PDO;
use \Parsedown;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait; // NEU

class AnnouncementController
{
    use ApiHandlerTrait; // NEU

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

    // ENTFERNT: Lokale handleApiRequest Methode

    public function getAnnouncements()
    {
        $this->handleApiRequest(function($data) {
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $user = $this->userRepo->findById($userId);
            if (!$user) {
                throw new Exception("Benutzer nicht gefunden.");
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
            echo json_encode(['success' => true, 'data' => $announcements], JSON_THROW_ON_ERROR);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // Entspricht requireLogin()
        ]);
    }

    public function createAnnouncement()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_POST
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $title = trim($data['title'] ?? '');
            $content = trim($data['content'] ?? '');
            if (empty($title) || empty($content)) {
                throw new Exception("Titel und Inhalt dürfen nicht leer sein.");
            }
            $targetRole = 'all';
            $targetClassId = null;
            if ($userRole === 'lehrer') {
                $targetClassId = filter_var($data['target_class_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$targetClassId) { throw new Exception("Lehrer müssen eine Klasse auswählen."); }
                $targetRole = 'schueler';
            }
            elseif (in_array($userRole, ['admin', 'planer'])) {
                $isGlobal = isset($data['target_global']) && $data['target_global'] === '1';
                $isTeacher = isset($data['target_teacher']) && $data['target_teacher'] === '1';
                $isPlaner = isset($data['target_planer']) && $data['target_planer'] === '1';
                $selectedClassId = filter_var($data['target_class_id'] ?? null, FILTER_VALIDATE_INT);
                $selectedClassId = ($selectedClassId === false || $selectedClassId === 0) ? null : $selectedClassId;
                $checkedCount = ($isGlobal ? 1 : 0) + ($isTeacher ? 1 : 0) + ($isPlaner ? 1 : 0);
                if ($checkedCount > 1) { throw new Exception("Bitte nur eine Zielgruppen-Checkbox auswählen."); }
                elseif ($checkedCount === 1) {
                    if ($isGlobal) $targetRole = 'all'; elseif ($isTeacher) $targetRole = 'lehrer'; elseif ($isPlaner) $targetRole = 'planer';
                    $targetClassId = null;
                } elseif ($selectedClassId !== null) {
                    $targetRole = 'schueler'; $targetClassId = $selectedClassId;
                } else { $targetRole = 'all'; $targetClassId = null; }
            } else { throw new Exception("Unbekannte Benutzerrolle."); }

            $attachmentPath = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                if (!in_array($userRole, ['admin', 'planer'])) { throw new Exception("Nur Admins und Planer dürfen Dateien anhängen."); }
                $uploadDir = dirname(__DIR__, 2) . '/public/uploads/announcements/';
                if (!is_dir($uploadDir)) { if (!mkdir($uploadDir, 0775, true)) { throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden."); } }
                $fileName = uniqid('', true) . '_' . basename($_FILES['attachment']['name']); $targetFile = $uploadDir . $fileName;
                $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $fileType = mime_content_type($_FILES['attachment']['tmp_name']);
                if (!in_array($fileType, $allowedTypes)) { throw new Exception("Ungültiger Dateityp."); }
                if ($_FILES['attachment']['size'] > 5 * 1024 * 1024) { throw new Exception("Datei ist zu groß (max. 5MB)."); }
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) { $attachmentPath = 'uploads/announcements/' . $fileName; }
                else { throw new Exception("Fehler beim Hochladen der Datei."); }
            }

            $newId = $this->announcementRepo->createAnnouncement(
                $userId,
                $title,
                $content,
                $targetRole,
                $targetClassId,
                $attachmentPath
            );
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

            echo json_encode(['success' => true, 'message' => 'Ankündigung erfolgreich erstellt.', 'data' => $newAnnouncement], JSON_THROW_ON_ERROR);

            return [
                'log_action' => 'create_announcement',
                'log_target_type' => 'announcement',
                'log_target_id' => $newId,
                'log_details' => [
                    'title' => $title,
                    'target_role' => $targetRole,
                    'target_class_id' => $targetClassId,
                    'has_attachment' => !empty($attachmentPath)
                ]
            ];
        }, [
            'inputType' => 'form', // Wegen $_FILES
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }

    public function deleteAnnouncement()
    {
        $this->handleApiRequest(function($data) { // $data von $_POST
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $announcementId = filter_var($data['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$announcementId) { throw new Exception("Ungültige Ankündigungs-ID."); }
            $announcement = $this->announcementRepo->getAnnouncementById($announcementId);
            if (!$announcement) { throw new Exception("Ankündigung nicht gefunden."); }
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
            
            return [
                'log_action' => 'delete_announcement',
                'log_target_type' => 'announcement',
                'log_target_id' => $announcementId,
                'log_details' => ['title' => $announcement['title'] ?? 'N/A']
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => ['admin', 'planer', 'lehrer']
        ]);
    }
}