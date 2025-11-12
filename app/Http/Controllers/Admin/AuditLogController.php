<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use PDO;
use Exception;
use App\Http\Traits\ApiHandlerTrait; // NEU

class AuditLogController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private AuditLogRepository $logRepo;
    private UserRepository $userRepo;
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->logRepo = new AuditLogRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
    }

    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();
        $page_title = 'Audit Log (Protokoll)';
        $body_class = 'admin-dashboard-body';
        try {
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

    public function getLogsApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
            $limit = 20;
            $filters = [
                'user_id' => filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
                'action' => filter_var($data['action'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'target_type' => filter_var($data['target_type'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'start_date' => filter_var($data['start_date'] ?? null, FILTER_UNSAFE_RAW) ?: null,
                'end_date' => filter_var($data['end_date'] ?? null, FILTER_UNSAFE_RAW) ?: null,
            ];
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
            ], JSON_THROW_ON_ERROR);

            return ['is_get_request' => true]; // Signal an Trait, nicht doppelt zu encoden
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }
}