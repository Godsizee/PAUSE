<?php
// app/Http/Controllers/Admin/AuditLogController.php

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository; // Wichtig
use PDO;
use Exception;

class AuditLogController
{
    private PDO $pdo;
    private AuditLogRepository $logRepo;
    private UserRepository $userRepo; // Wichtig

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->logRepo = new AuditLogRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo); // Wichtig
    }

    /**
     * Zeigt die Hauptseite des Audit-Logs an.
     * Lädt Filter-Optionen.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Audit Log (Protokoll)';
        $body_class = 'admin-dashboard-body';

        try {
            // Lade Filter-Daten
            // KORREKTUR: Variablennamen auf camelCase geändert, um den View-Fehler (Warning) zu beheben
            $availableUsers = $this->userRepo->getAll(); // Holt alle Benutzer
            $availableActions = $this->logRepo->getDistinctActions();
            $availableTargetTypes = $this->logRepo->getDistinctTargetTypes();

            include_once dirname(__DIR__, 4) .'/pages/admin/audit_logs.php';
        } catch (Exception $e) {
            error_log("Fehler beim Laden der Audit-Log-Seite: " . $e->getMessage());
            // This is likely where the 500 error page is generated
            http_response_code(500);
            die("Ein kritischer Fehler ist aufgetreten: " . $e->getMessage());
        }
    }

    /**
     * API-Endpunkt zum Abrufen von Log-Daten.
     */
    public function getLogsApi()
    {
        Security::requireRole('admin');
        header('Content-Type: application/json');

        try {
            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $limit = 20; // Festgelegt

            // KORREKTUR: Verwende FILTER_UNSAFE_RAW (oder lasse Filter für Strings weg, da sie in WHERE-Klauseln verwendet werden)
            // Statt FILTER_SANITIZE_STRING (veraltet/entfernt)
            $filters = [
                'user_id' => filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: null,
                'action' => filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW) ?: null,
                'target_type' => filter_input(INPUT_GET, 'target_type', FILTER_UNSAFE_RAW) ?: null,
                'start_date' => filter_input(INPUT_GET, 'start_date', FILTER_UNSAFE_RAW) ?: null,
                'end_date' => filter_input(INPUT_GET, 'end_date', FILTER_UNSAFE_RAW) ?: null,
            ];
            
            // Entferne leere Filter-Werte
            $filters = array_filter($filters, function($value) {
                return $value !== null && $value !== '';
            }); 

            $logs = $this->logRepo->getLogs($page, $limit, $filters);
            $totalCount = $this->logRepo->getLogsCount($filters);
            $totalPages = ceil($totalCount / $limit);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $totalPages,
                    'totalCount' => $totalCount,
                    'limit' => $limit
                ]
            ], JSON_THROW_ON_ERROR); // JSON_THROW_ON_ERROR hilft bei Debugging

        } catch (Exception $e) {
            http_response_code(500);
            // Dies ist der Fehler, den JS empfängt
            error_log("AuditLog API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString()); // Logge den echten PHP-Fehler
            echo json_encode(['success' => false, 'message' => 'Serverfehler beim Abrufen der Logs: ' . $e->getMessage()]);
        }
        exit;
    }
}

