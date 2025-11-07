<?php
// app/Http/Controllers/Admin/AuditLogController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. Methode getLogsApi() refaktorisiert, um das Trait zu nutzen.
// 3. 'inputType' => 'get' gesetzt, $data ist nun $_GET.
// 4. Manuelle Fehlerbehandlung und json_encode entfernt.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren
use PDO;
use Exception;

class AuditLogController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

    private PDO $pdo;
    private AuditLogRepository $logRepo;
    private UserRepository $userRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->logRepo = new AuditLogRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
    }

    /**
     * Zeigt die Hauptseite des Audit-Logs an.
     * Lädt Filter-Optionen.
     * (Unverändert)
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
            $availableUsers = $this->userRepo->getAll();
            $availableActions = $this->logRepo->getDistinctActions();
            $availableTargetTypes = $this->logRepo->getDistinctTargetTypes();

            include_once dirname(__DIR__, 4) .'/pages/admin/audit_logs.php';
        } catch (Exception $e) {
            error_log("Fehler beim Laden der Audit-Log-Seite: " . $e->getMessage());
            http_response_code(500);
            die("Ein kritischer Fehler ist aufgetreten: " . $e->getMessage());
        }
    }

    /**
     * API-Endpunkt zum Abrufen von Log-Daten.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getLogsApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $limit = 20; // Festgelegt

            // Filter direkt aus $data (das bereits $_GET ist) holen
            $filters = [
                'user_id' => filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
                'action' => filter_var($data['action'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'target_type' => filter_var($data['target_type'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'start_date' => filter_var($data['start_date'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'end_date' => filter_var($data['end_date'] ?? null, FILTER_UNSAFE_RAW) ?: null,
            ];
            
            // Entferne leere Filter-Werte
            $filters = array_filter($filters, function($value) {
                return $value !== null && $value !== '';
            }); 

            $logs = $this->logRepo->getLogs($page, $limit, $filters);
            $totalCount = $this->logRepo->getLogsCount($filters);
            $totalPages = ceil($totalCount / $limit);

            // Rückgabe für Trait (Erfolgsfall)
            // Der Trait kümmert sich um json_encode()
            return [
                'json_response' => [
                    'success' => true,
                    'logs' => $logs,
                    'pagination' => [
                        'currentPage' => $page,
                        'totalPages' => $totalPages,
                        'totalCount' => $totalCount,
                        'limit' => $limit
                    ]
                ]
            ];
            
        }, [
            'inputType' => 'get', // Dies ist eine GET-Anfrage
            'checkRole' => 'admin' // Sicherheitsprüfung durch das Trait
        ]);
    }
}