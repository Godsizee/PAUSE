<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\LoginAttemptRepository;
use App\Services\AuthenticationService;
use Exception;
use PDO;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait; // NEU

class UserController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private UserRepository $userRepository;
    private StammdatenRepository $stammdatenRepository;
    private AuthenticationService $authService;
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->stammdatenRepository = new StammdatenRepository($this->pdo);
        $loginAttemptRepository = new LoginAttemptRepository($this->pdo);
        $this->authService = new AuthenticationService($this->userRepository, $loginAttemptRepository);
    }
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

    // ENTFERNT: Lokale handleApiRequest Methode

    public function getUsers()
    {
        $this->handleApiRequest(function($data) {
            $users = $this->userRepository->getAll();
            $roles = $this->userRepository->getAvailableRoles();
            $classes = $this->stammdatenRepository->getClasses();
            $teachers = $this->stammdatenRepository->getTeachers();
            echo json_encode(['success' => true, 'data' => [
                'users' => $users,
                'roles' => $roles,
                'classes' => $classes,
                'teachers' => $teachers
            ]], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createUser()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
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
            'inputType' => 'json',
            'checkRole' => 'admin'
        ]);
    }

    public function updateUser()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $id = $data['user_id'] ?? null;
            if (!$id) {
                throw new Exception("Ungültige Benutzer-ID.");
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
            'inputType' => 'json',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteUser()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $id = $data['user_id'] ?? null;
            if (!$id) {
                throw new Exception("Ungültige ID.");
            }
            $this->userRepository->delete($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Benutzer erfolgreich gelöscht.'],
                'log_action' => 'delete_user',
                'log_target_type' => 'user',
                'log_target_id' => $id
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => 'admin'
        ]);
    }

    public function importUsers()
    {
        $this->handleApiRequest(function($data) { // $data (aus $_POST) wird ignoriert, wir verwenden $_FILES
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Keine CSV-Datei hochgeladen oder Fehler beim Upload.');
            }
            $tmpFilePath = $_FILES['csv_file']['tmp_name'];
            $fileType = mime_content_type($tmpFilePath);
            $fileExtension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($fileType, ['text/plain', 'text/csv', 'application/csv']) && $fileExtension !== 'csv') {
                throw new Exception('Ungültiger Dateityp. Bitte laden Sie eine CSV-Datei hoch.');
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

            echo json_encode(['success' => true, 'message' => 'Import abgeschlossen.', 'data' => $result]);

            return [
                'log_action' => 'import_users_csv',
                'log_target_type' => 'system',
                'log_target_id' => null,
                'log_details' => [
                    'filename' => $_FILES['csv_file']['name'],
                    'success_count' => $result['success_count'],
                    'failure_count' => $result['failure_count'],
                    'errors' => $result['errors']
                ]
            ];
        }, [
            'inputType' => 'form', // 'form' nutzen, um CSRF-Token-Prüfung auszulösen
            'checkRole' => 'admin'
        ]);
    }

    public function impersonateUserApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $targetUserId = $data['user_id'] ?? null;
            if (!$targetUserId) {
                throw new Exception("Keine Benutzer-ID zum Imitieren angegeben.", 400);
            }
            $currentAdminId = $_SESSION['user_id'];
            if ($targetUserId == $currentAdminId) {
                throw new Exception("Sie können sich nicht selbst imitieren.", 400);
            }
            $targetUser = $this->userRepository->findById($targetUserId);
            if (!$targetUser) {
                throw new Exception("Zielbenutzer nicht gefunden.", 404);
            }
            
            $_SESSION['impersonator_id'] = $currentAdminId;
            session_regenerate_id(true);
            $_SESSION['user_id'] = $targetUser['user_id'];
            $_SESSION['username'] = $targetUser['username'];
            $_SESSION['user_role'] = $targetUser['role'];
            $_SESSION['impersonator_id'] = $currentAdminId;
            Security::getCsrfToken(); // Neuen Token für die imitierte Sitzung generieren

            echo json_encode(['success' => true, 'redirectUrl' => Utils::url('dashboard')]);

            return [
                'log_action' => 'impersonate_start',
                'log_target_type' => 'user',
                'log_target_id' => $currentAdminId,
                'log_details' => ['target_user_id' => $targetUserId, 'target_username' => $targetUser['username']]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => 'admin'
        ]);
    }
}