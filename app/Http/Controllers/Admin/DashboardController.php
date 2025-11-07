<?php
// app/Http/Controllers/Admin/DashboardController.php

// MODIFIZIERT:
// 1. SystemHealthService importiert und im Konstruktor instanziiert.
// 2. Die private Methode performSystemChecks() wurde entfernt.
// 3. index() ruft jetzt $this->healthService->getSystemInfo() und
//    $this->healthService->performSystemChecks() auf, um die Daten zu laden.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\PlanRepository;
use App\Services\SystemHealthService; // NEU: Service importieren
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
    private SystemHealthService $healthService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepo = new UserRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->auditRepo = new AuditLogRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
        $this->healthService = new SystemHealthService(); // NEU
    }

    /**
     * VERALTET: Die Methode private function performSystemChecks() wurde entfernt.
     * Die Logik befindet sich jetzt im SystemHealthService.
     */
    // private function performSystemChecks(): array { ... } // (ENTFERNT)


    /**
     * Zeigt das Admin-Dashboard an.
     * MODIFIZIERT: Ruft System-Infos und Checks vom SystemHealthService ab.
     */
    public function index()
    {
        Security::requireRole('admin');

        global $config;
        $config = Database::getConfig();

        $page_title = 'Admin Dashboard';
        $body_class = 'admin-dashboard-body';

        $dashboardData = [];
        try {
            // Statistiken
            $dashboardData['userCounts'] = $this->userRepo->countUsersByRole();
            $dashboardData['totalUsers'] = array_sum($dashboardData['userCounts']);
            $dashboardData['classCount'] = $this->stammdatenRepo->countClasses();
            $dashboardData['teacherCount'] = $this->stammdatenRepo->countTeachers();
            $dashboardData['subjectCount'] = $this->stammdatenRepo->countSubjects();
            $dashboardData['roomCount'] = $this->stammdatenRepo->countRooms();

            // Letzte Audit-Logs
            $dashboardData['latestLogs'] = $this->auditRepo->getLogs(1, 5);

            // Aktuelle Einstellungen
            $dashboardData['settings'] = Utils::getSettings();

            // NEU: System-Infos und Checks vom Service holen
            $dashboardData['systemInfo'] = $this->healthService->getSystemInfo();
            $dashboardData['systemChecks'] = $this->healthService->performSystemChecks();

            // Veröffentlichungsstatus
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