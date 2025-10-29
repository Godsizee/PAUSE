<?php
// app/Http/Controllers/Admin/DashboardController.php
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

    /**
     * NEU: Private Hilfsfunktion für System-Checks
     * KORRIGIERT: Labels gekürzt und Tooltips hinzugefügt.
     */
    private function performSystemChecks(): array
    {
        $checks = [];
        $basePublicDir = dirname(__DIR__, 4) . '/public/';

        // 1. Datenbankverbindung
        $checks['database'] = [
            'label' => 'Datenbank-Verbindung',
            'status' => true,
            'message' => 'OK',
            'tooltip' => 'Die Verbindung zur MySQL-Datenbank ist aktiv.'
        ];

        // 2. Konfigurationsdatei-Check
        $checks['config_file'] = [
            'label' => 'Konfigurationsdatei',
            'status' => true,
            'message' => 'OK',
            'tooltip' => 'Datei: database_access.php (Geladen)'
        ];
        
        // 3. PHP Extension: PDO MySQL
        $checks['ext_pdo_mysql'] = [
            'label' => 'PHP Extension: pdo_mysql',
            'status' => extension_loaded('pdo_mysql'),
            'message' => extension_loaded('pdo_mysql') ? 'OK' : 'Fehlt!', // Gekürzt
            'tooltip' => extension_loaded('pdo_mysql') ? 'Erweiterung ist geladen.' : 'Erforderlich für die Datenbankverbindung.'
        ];
        
        // 4. PHP Extension: GD
        $checks['ext_gd'] = [
            'label' => 'PHP Extension: GD',
            'status' => extension_loaded('gd'),
            'message' => extension_loaded('gd') ? 'OK' : 'Optional', // Gekürzt
            'tooltip' => extension_loaded('gd') ? 'Erweiterung ist geladen.' : 'Optional (wird für zukünftige Bildverarbeitung genutzt).'
        ];

        // 5. Upload-Verzeichnisse prüfen
        $uploadDirs = [
            'upload_dir_announcements' => 'uploads/announcements/',
            'upload_dir_branding' => 'uploads/branding/'
        ];

        foreach ($uploadDirs as $key => $dir) {
            $fullPath = $basePublicDir . $dir;
            $label = 'Verzeichnis: ' . basename($dir); // Gekürztes Label
            $tooltip = 'Pfad: ' . $dir; // Voller Pfad im Tooltip

            if (!is_dir($fullPath)) {
                // Versuche, das Verzeichnis zu erstellen
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
                // Verzeichnis existiert, teste Schreibzugriff
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
        // Stellt sicher, dass nur Admins auf diese Seite zugreifen können.
        Security::requireRole('admin');

        global $config; // Wird für die Basis-URL in den Views benötigt.
        $config = Database::getConfig();

        $page_title = 'Admin Dashboard';
        $body_class = 'admin-dashboard-body'; // Spezifische Klasse für Admin-Styling

        // --- NEU: Daten für das Dashboard laden ---
        $dashboardData = [];
        try {
            // Statistiken
            $dashboardData['userCounts'] = $this->userRepo->countUsersByRole();
            $dashboardData['totalUsers'] = array_sum($dashboardData['userCounts']);
            $dashboardData['classCount'] = $this->stammdatenRepo->countClasses();
            $dashboardData['teacherCount'] = $this->stammdatenRepo->countTeachers();
            $dashboardData['subjectCount'] = $this->stammdatenRepo->countSubjects();
            $dashboardData['roomCount'] = $this->stammdatenRepo->countRooms();

            // Letzte Audit-Logs (z.B. die letzten 5)
            $dashboardData['latestLogs'] = $this->auditRepo->getLogs(1, 5); // Seite 1, Limit 5

            // Aktuelle Einstellungen (für Wartungsmodus-Schalter)
            $dashboardData['settings'] = Utils::getSettings();

            // NEU: System-Infos
            $dashboardData['systemInfo']['php'] = phpversion();
            $dashboardData['systemInfo']['db'] = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $dashboardData['systemInfo']['webserver'] = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
            
            // NEU: System-Checks
            $dashboardData['systemChecks'] = $this->performSystemChecks();

            // NEU: Veröffentlichungsstatus
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
            // Setze leere Werte, um Fehler in der View zu vermeiden
            $dashboardData['userCounts'] = [];
            $dashboardData['totalUsers'] = 0;
            $dashboardData['classCount'] = 0;
            $dashboardData['teacherCount'] = 0;
            $dashboardData['subjectCount'] = 0;
            $dashboardData['roomCount'] = 0;
            $dashboardData['latestLogs'] = [];
            $dashboardData['settings'] = Utils::getSettings(); // Lade zumindest Standardeinstellungen
            $dashboardData['systemInfo'] = ['php' => 'N/A', 'db' => 'N/A', 'webserver' => 'N/A'];
            $dashboardData['systemChecks'] = []; // Leere Checks bei Fehler
            $dashboardData['publishStatus'] = ['current' => [], 'next' => [], 'currentWeekNum' => (int)date('W'), 'nextWeekNum' => (int)(new DateTime('+1 week'))->format('W')];
            // Optional: Zeige eine Fehlermeldung auf dem Dashboard an
            $dashboardData['error'] = "Einige Dashboard-Daten konnten nicht geladen werden.";
        }
        // --- ENDE: Daten laden ---

        // CSRF Token für Aktionen wie Wartungsmodus-Schalter generieren
        Security::getCsrfToken();

        require_once dirname(__DIR__, 4) . '/pages/admin/dashboard.php';
    }
}