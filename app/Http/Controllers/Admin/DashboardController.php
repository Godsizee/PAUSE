<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\PlanRepository;
use PDO;
use Exception;
use DateTime;
class DashboardController
{
    private PDO $pdo;
    private UserRepository $userRepo;
    private StammdatenRepository $stammdatenRepo;
    private AuditLogRepository $auditRepo;
    private PlanRepository $planRepo;
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepo = new UserRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->auditRepo = new AuditLogRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
    }
    private function performSystemChecks(): array
    {
        $checks = [];
        $basePublicDir = dirname(__DIR__, 4) . '/public/';
        $checks['database'] = [
            'label' => 'Datenbank-Verbindung',
            'status' => true,
            'message' => 'OK',
            'tooltip' => 'Die Verbindung zur MySQL-Datenbank ist aktiv.'
        ];
        $checks['config_file'] = [
            'label' => 'Konfigurationsdatei',
            'status' => true,
            'message' => 'OK',
            'tooltip' => 'Datei: database_access.php (Geladen)'
        ];
        $checks['ext_pdo_mysql'] = [
            'label' => 'PHP Extension: pdo_mysql',
            'status' => extension_loaded('pdo_mysql'),
            'message' => extension_loaded('pdo_mysql') ? 'OK' : 'Fehlt!', 
            'tooltip' => extension_loaded('pdo_mysql') ? 'Erweiterung ist geladen.' : 'Erforderlich für die Datenbankverbindung.'
        ];
        $checks['ext_gd'] = [
            'label' => 'PHP Extension: GD',
            'status' => extension_loaded('gd'),
            'message' => extension_loaded('gd') ? 'OK' : 'Optional', 
            'tooltip' => extension_loaded('gd') ? 'Erweiterung ist geladen.' : 'Optional (wird für zukünftige Bildverarbeitung genutzt).'
        ];
        $uploadDirs = [
            'upload_dir_announcements' => 'uploads/announcements/',
            'upload_dir_branding' => 'uploads/branding/'
        ];
        foreach ($uploadDirs as $key => $dir) {
            $fullPath = $basePublicDir . $dir;
            $label = 'Verzeichnis: ' . basename($dir); 
            $tooltip = 'Pfad: ' . $dir; 
            if (!is_dir($fullPath)) {
                if (!@mkdir($fullPath, 0775, true)) {
                     $checks[$key] = [
                        'label' => $label,
                        'status' => false,
                        'message' => 'Fehler (Erstellen)',
                        'tooltip' => $tooltip . ' - Nicht gefunden & konnte nicht erstellt werden.'
                     ];
                } else {
                     $checks[$key] = [
                        'label' => $label,
                        'status' => true,
                        'message' => 'OK',
                        'tooltip' => $tooltip . ' (OK, wurde gerade erstellt)'
                     ];
                }
            } else {
                $testFile = $fullPath . 'write_test_' . uniqid() . '.tmp';
                if (@file_put_contents($testFile, 'test') !== false) {
                    @unlink($testFile);
                    $checks[$key] = [
                        'label' => $label,
                        'status' => true,
                        'message' => 'OK',
                        'tooltip' => $tooltip . ' (Beschreibbar)'
                    ];
                } else {
                    $checks[$key] = [
                        'label' => $label,
                        'status' => false,
                        'message' => 'Fehler (Schreibrechte)',
                        'tooltip' => $tooltip . ' - Nicht beschreibbar! (Berechtigungen prüfen)'
                    ];
                }
            }
        }
        return $checks;
    }
    public function index()
    {
        Security::requireRole('admin');
        global $config; 
        $config = Database::getConfig();
        $page_title = 'Admin Dashboard';
        $body_class = 'admin-dashboard-body'; 
        $dashboardData = [];
        try {
            $dashboardData['userCounts'] = $this->userRepo->countUsersByRole();
            $dashboardData['totalUsers'] = array_sum($dashboardData['userCounts']);
            $dashboardData['classCount'] = $this->stammdatenRepo->countClasses();
            $dashboardData['teacherCount'] = $this->stammdatenRepo->countTeachers();
            $dashboardData['subjectCount'] = $this->stammdatenRepo->countSubjects();
            $dashboardData['roomCount'] = $this->stammdatenRepo->countRooms();
            $dashboardData['latestLogs'] = $this->auditRepo->getLogs(1, 5); 
            $dashboardData['settings'] = Utils::getSettings();
            $dashboardData['systemInfo']['php'] = phpversion();
            $dashboardData['systemInfo']['db'] = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $dashboardData['systemInfo']['webserver'] = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
            $dashboardData['systemChecks'] = $this->performSystemChecks();
            $currentYear = (int)date('o');
            $currentWeek = (int)date('W');
            $nextWeekDate = new DateTime('+1 week');
            $nextYear = (int)$nextWeekDate->format('o');
            $nextWeek = (int)$nextWeekDate->format('W');
            $dashboardData['publishStatus']['currentWeekNum'] = $currentWeek;
            $dashboardData['publishStatus']['nextWeekNum'] = $nextWeek;
            $dashboardData['publishStatus']['current'] = $this->planRepo->getPublishStatus($currentYear, $currentWeek);
            $dashboardData['publishStatus']['next'] = $this->planRepo->getPublishStatus($nextYear, $nextWeek);
        } catch (Exception $e) {
            error_log("Fehler beim Laden der Admin-Dashboard-Daten: " . $e->getMessage());
            $dashboardData['userCounts'] = [];
            $dashboardData['totalUsers'] = 0;
            $dashboardData['classCount'] = 0;
            $dashboardData['teacherCount'] = 0;
            $dashboardData['subjectCount'] = 0;
            $dashboardData['roomCount'] = 0;
            $dashboardData['latestLogs'] = [];
            $dashboardData['settings'] = Utils::getSettings(); 
            $dashboardData['systemInfo'] = ['php' => 'N/A', 'db' => 'N/A', 'webserver' => 'N/A'];
            $dashboardData['systemChecks'] = []; 
            $dashboardData['publishStatus'] = ['current' => [], 'next' => [], 'currentWeekNum' => (int)date('W'), 'nextWeekNum' => (int)(new DateTime('+1 week'))->format('W')];
            $dashboardData['error'] = "Einige Dashboard-Daten konnten nicht geladen werden.";
        }
        Security::getCsrfToken();
        require_once dirname(__DIR__, 4) . '/pages/admin/dashboard.php';
    }
}