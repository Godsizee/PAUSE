<?php
// app/Http/Controllers/Admin/AnnouncementController.php

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\UserRepository;
use Exception;
use PDO;
use \Parsedown; // KORREKTUR: Parsedown aus dem globalen Namespace importieren

class AnnouncementController
{
    private PDO $pdo;
    private AnnouncementRepository $announcementRepo;
    private StammdatenRepository $stammdatenRepo;
    private UserRepository $userRepo;
    private Parsedown $parsedown; // KORREKTUR: Typehint kann jetzt ohne Backslash sein

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->announcementRepo = new AnnouncementRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->parsedown = new Parsedown(); // KORREKTUR: Kann jetzt direkt verwendet werden
        $this->parsedown->setSafeMode(true); 
    }

    /**
     * Zeigt die Hauptseite für die Ankündigungsverwaltung an.
     * Stellt Daten für das Formular bereit (Klassenliste).
     */
    public function index()
    {
        Security::requireRole(['admin', 'planer', 'lehrer']);
        global $config;
        $config = Database::getConfig();

        $page_title = 'Ankündigungsverwaltung';
        $body_class = 'admin-dashboard-body';

        try {
             $userRole = $_SESSION['user_role'] ?? 'Unbekannt';
             $userId = $_SESSION['user_id'] ?? null;
             $user = $userId ? $this->userRepo->findById($userId) : null;
             $allAnnouncements = $this->announcementRepo->getAllAnnouncementsWithDetails();
             $availableClasses = [];
             if (in_array($userRole, ['admin', 'planer'])) {
                 $availableClasses = $this->stammdatenRepo->getClasses();
             } elseif ($userRole === 'lehrer' && $user && isset($user['teacher_id'])) {
                 $availableClasses = $this->stammdatenRepo->getClasses(); // TODO: Ggf. auf Lehrer-Klassen einschränken
             }

             // Convert content to HTML and add file URL
             foreach ($allAnnouncements as &$announcement) {
                 // Convert Markdown content to safe HTML
                 $announcement['content_html'] = $this->parsedown->text($announcement['content'] ?? '');

                 // Add file URL
                if (!empty($announcement['file_path'])) {
                    $announcement['file_url'] = rtrim($config['base_url'], '/') . '/' . ltrim($announcement['file_path'], '/');
                } else {
                    $announcement['file_url'] = null;
                }
             }
             unset($announcement); // Break reference


            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) .'/pages/admin/announcements.php';

        } catch (Exception $e) {
             error_log("Error loading announcement admin page: " . $e->getMessage());
             http_response_code(500);
             die("Ein Fehler ist beim Laden der Seite aufgetreten: " . $e->getMessage());
        }
    }
}