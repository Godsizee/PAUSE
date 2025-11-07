<?php
// app/Http/Controllers/Admin/UserController.php

// MODIFIZIERT:
// 1. ImpersonationService importiert und im Konstruktor injiziert.
// 2. impersonateUserApi() wurde refaktorisiert:
//    - Die gesamte Session-Logik wurde entfernt.
//    - Ruft jetzt $this->impersonationService->start() auf.
//    - Nutzt die Rückgabe des Service für das Audit-Log.
// KORREKTUR: Duplizierter Code (Zeile 63-102) entfernt und index() wiederhergestellt.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\LoginAttemptRepository;
use App\Services\AuthenticationService;
use App\Services\ImpersonationService; // NEU: Service importieren
use App\Http\Traits\ApiHandlerTrait;
use Exception;
use PDO;
use App\Services\AuditLogger;

class UserController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private UserRepository $userRepository;
    private StammdatenRepository $stammdatenRepository;
    private AuthenticationService $authService;
    private ImpersonationService $impersonationService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->stammdatenRepository = new StammdatenRepository($this->pdo);
        
        $loginAttemptRepository = new LoginAttemptRepository($this->pdo);
        $this->authService = new AuthenticationService($this->userRepository, $loginAttemptRepository);
        
        // NEU: ImpersonationService instanziieren (benötigt UserRepository)
        $this->impersonationService = new ImpersonationService($this->userRepository);
    }

    /**
     * Zeigt die Hauptseite für die Benutzerverwaltung an.
     * KORRIGIERT: Korrekte Implementierung wiederhergestellt.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Benutzerverwaltung';
        $body_class = 'admin-dashboard-body';
        
        Security::getCsrfToken();

        include_once dirname(__DIR__, 4) . '/pages/admin/users.php';
    }

    /**
     * API: Holt alle Benutzer und gibt sie als JSON zurück. (GET request)
     * (Unverändert)
     */
    public function getUsers()
    {
        $this->handleApiRequest(function($data) {
            
            $users = $this->userRepository->getAll();
            $roles = $this->userRepository->getAvailableRoles();
            $classes = $this->stammdatenRepository->getClasses();
            $teachers = $this->stammdatenRepository->getTeachers();

            return [
                'json_response' => [
                    'success' => true, 
                    'data' => [
                        'users' => $users,
                        'roles' => $roles,
                        'classes' => $classes,
                        'teachers' => $teachers
                    ]
                ]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Erstellt einen neuen Benutzer. (FormData request)
     * (Unverändert)
     */
    public function createUser()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $newUserId = $this->userRepository->create($data);
            $newUser = $this->userRepository->findById($newUserId);

            $details = $data;
            unset($details['password']);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Benutzer erfolgreich erstellt.', 'data' => $newUser],
                'log_action' => 'create_user',
                'log_target_type' => 'user',
                'log_target_id' => $newUserId,
                'log_details' => $details
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Aktualisiert einen bestehenden Benutzer. (FormData request)
     * (Unverändert)
     */
    public function updateUser()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $id = $data['user_id'] ?? null;
            if (!$id) {
                throw new Exception("Ungültige Benutzer-ID.", 400);
            }
            
            $this->userRepository->update($id, $data);
            $updatedUser = $this->userRepository->findById($id);

            $details = $data;
            unset($details['password']);

            return [
                'json_response' => ['success' => true, 'message' => 'Benutzer erfolgreich aktualisiert.', 'data' => $updatedUser],
                'log_action' => 'update_user',
                'log_target_type' => 'user',
                'log_target_id' => $id,
                'log_details' => $details
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Löscht einen Benutzer. (FormData request)
     * (Unverändert)
     */
    public function deleteUser()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $id = $data['user_id'] ?? null;
            if (!$id) {
                throw new Exception("Ungültige ID.", 400);
            }
            
            $user = $this->userRepository->findById($id);
            $details = ['username' => $user['username'] ?? 'N/A'];
            
            $this->userRepository->delete($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Benutzer erfolgreich gelöscht.'],
                'log_action' => 'delete_user',
                'log_target_type' => 'user',
                'log_target_id' => $id,
                'log_details' => $details
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Importiert Benutzer aus einer CSV-Datei. (FormData request)
     * (Unverändert)
     */
    public function importUsers()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Keine CSV-Datei hochgeladen oder Fehler beim Upload.', 400);
            }

            $tmpFilePath = $_FILES['csv_file']['tmp_name'];
            $fileType = mime_content_type($tmpFilePath);
            $fileExtension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if (!in_array($fileType, ['text/plain', 'text/csv', 'application/csv']) && $fileExtension !== 'csv') {
                 throw new Exception('Ungültiger Dateityp. Bitte laden Sie eine CSV-Datei hoch.', 400);
            }

            $validClasses = $this->stammdatenRepository->getClasses();
            $validTeachers = $this->stammdatenRepository->getTeachers();
            $validRoles = $this->userRepository->getAvailableRoles();
            
            $validationData = [
                'class_ids' => array_column($validClasses, 'class_id'),
                'teacher_ids' => array_column($validTeachers, 'teacher_id'),
                'roles' => $validRoles
            ];

            $result = $this->userRepository->importFromCSV($tmpFilePath, $validationData);

            $logDetails = [
                'filename' => $_FILES['csv_file']['name'],
                'success_count' => $result['success_count'],
                'failure_count' => $result['failure_count'],
                'errors' => array_slice($result['errors'], 0, 10)
            ];

            return [
                'json_response' => ['success' => true, 'message' => 'Import abgeschlossen.', 'data' => $result],
                'log_action' => 'import_users_csv',
                'log_target_type' => 'system',
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }
    
    /**
     * API-Endpunkt zum Starten der User-Impersonation. (JSON request)
     * (Unverändert)
     */
    public function impersonateUserApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $targetUserId = $data['user_id'] ?? null;
            if (!$targetUserId) {
                throw new Exception("Keine Benutzer-ID zum Imitieren angegeben.", 400);
            }
            $currentAdminId = $_SESSION['user_id'];

            $targetUser = $this->impersonationService->start((int)$targetUserId, (int)$currentAdminId);

            $logDetails = [
                'target_user_id' => $targetUserId, 
                'target_username' => $targetUser['username'] ?? 'N/A'
            ];

            return [
                'json_response' => ['success' => true, 'redirectUrl' => Utils::url('dashboard')],
                'log_action' => 'impersonate_start',
                'log_target_type' => 'user',
                'log_target_id' => $currentAdminId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'admin'
        ]);
    }
}