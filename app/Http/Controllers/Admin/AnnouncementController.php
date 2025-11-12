<?php
namespace App\Http\Controllers\Admin;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\AnnouncementRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\UserRepository;
use Exception;
use PDO;
use \Parsedown; 
class AnnouncementController
{
    private PDO $pdo;
    private AnnouncementRepository $announcementRepo;
    private StammdatenRepository $stammdatenRepo;
    private UserRepository $userRepo;
    private Parsedown $parsedown; 
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->announcementRepo = new AnnouncementRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->parsedown = new Parsedown(); 
        $this->parsedown->setSafeMode(true); 
    }
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
                 $availableClasses = $this->stammdatenRepo->getClasses(); 
             }
             foreach ($allAnnouncements as &$announcement) {
                 $announcement['content_html'] = $this->parsedown->text($announcement['content'] ?? '');
                if (!empty($announcement['file_path'])) {
                    $announcement['file_url'] = rtrim($config['base_url'], '/') . '/' . ltrim($announcement['file_path'], '/');
                } else {
                    $announcement['file_url'] = null;
                }
             }
             unset($announcement); 
            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) .'/pages/admin/announcements.php';
        } catch (Exception $e) {
             error_log("Error loading announcement admin page: " . $e->getMessage());
             http_response_code(500);
             die("Ein Fehler ist beim Laden der Seite aufgetreten: " . $e->getMessage());
        }
    }
}