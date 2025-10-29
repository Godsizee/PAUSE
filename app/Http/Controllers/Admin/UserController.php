<?php
// app/Http/Controllers/Admin/UserController.php
namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use Exception;
use PDO;
use App\Services\AuditLogger; // NEU: AuditLogger importieren

class UserController
{
    private PDO $pdo;
    private UserRepository $userRepository;
    private StammdatenRepository $stammdatenRepository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->stammdatenRepository = new StammdatenRepository($this->pdo);
    }

    /**
     * Zeigt die Hauptseite für die Benutzerverwaltung an.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Benutzerverwaltung';
        $body_class = 'admin-dashboard-body';
        // Ensure CSRF token is generated for forms on this page
        Security::getCsrfToken();

        include_once dirname(__DIR__, 4) . '/pages/admin/users.php';
    }

    // --- API Helper ---
    /**
     * Helper to wrap common API request logic (auth, CSRF, response type, error handling)
     * @param callable $callback The actual logic to execute
     * @param string $actionName Der Name der Aktion für das Audit-Log (z.B. 'create_user')
     * @param string $targetType Der Typ des Ziels (z.B. 'user')
     */
    private function handleApiRequest(callable $callback, string $actionName = '', string $targetType = ''): void
    {
        header('Content-Type: application/json'); // Set header early
        try {
            Security::verifyCsrfToken(); // Verify CSRF token here for all modifying actions
            
            // Führe die eigentliche Aktion aus
            $result = $callback(); // Callback gibt jetzt ggf. Daten zurück

            // Protokollierung bei Erfolg (nur wenn actionName gesetzt ist)
            if ($actionName) {
                AuditLogger::log(
                    $actionName,
                    $targetType,
                    $result['target_id'] ?? null, // ID des erstellten/bearbeiteten Objekts
                    $result['details'] ?? null   // Details (z.B. Name)
                );
            }

        } catch (Exception $e) {
            // Determine appropriate status code
            // Hinzugefügt: 409 Conflict-Status für Duplikate
            http_response_code(str_contains($e->getMessage(), 'CSRF') ? 403 : (str_contains($e->getMessage(), 'vergeben') ? 409 : 400));
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }

    // --- API METHODS ---

    /**
     * API: Holt alle Benutzer und gibt sie als JSON zurück. (GET request - no CSRF needed)
     */
    public function getUsers()
    {
        Security::requireRole('admin');
        header('Content-Type: application/json');
        try {
            $users = $this->userRepository->getAll();
            // Additionally, fetch data needed for forms
            $roles = $this->userRepository->getAvailableRoles();
            $classes = $this->stammdatenRepository->getClasses();
            $teachers = $this->stammdatenRepository->getTeachers();

            echo json_encode(['success' => true, 'data' => [
                'users' => $users,
                'roles' => $roles,
                'classes' => $classes,
                'teachers' => $teachers
            ]], JSON_THROW_ON_ERROR); // Added JSON_THROW_ON_ERROR
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Benutzer: ' . $e->getMessage()], JSON_THROW_ON_ERROR); // Added JSON_THROW_ON_ERROR
        }
        exit();
    }

    /**
     * API: Erstellt einen neuen Benutzer. (POST request)
     */
    public function createUser()
    {
        Security::requireRole('admin'); // Auth check first
        $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $newUserId = $this->userRepository->create($_POST);
            // Optionally fetch the newly created user data to return
            $newUser = $this->userRepository->findById($newUserId); // Assuming findById exists
            echo json_encode(['success' => true, 'message' => 'Benutzer erfolgreich erstellt.', 'data' => $newUser], JSON_THROW_ON_ERROR);

            // Details für Audit-Log vorbereiten (Passwort nicht loggen)
            $details = $_POST;
            unset($details['password']);
            
            return [
                'target_id' => $newUserId,
                'details' => $details
            ];
        }, 'create_user', 'user'); // NEU
    }

    /**
     * API: Aktualisiert einen bestehenden Benutzer. (POST request)
     */
    public function updateUser()
    {
        Security::requireRole('admin'); // Auth check first
        $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception("Ungültige Benutzer-ID.");
            }
            $this->userRepository->update($id, $_POST);
             // Optionally fetch the updated user data to return
            $updatedUser = $this->userRepository->findById($id);
            echo json_encode(['success' => true, 'message' => 'Benutzer erfolgreich aktualisiert.', 'data' => $updatedUser], JSON_THROW_ON_ERROR);
            
            // Details für Audit-Log vorbereiten (Passwort nicht loggen)
            $details = $_POST;
            unset($details['password']); // Niemals das Passwort loggen

            return [
                'target_id' => $id,
                'details' => $details
            ];
        }, 'update_user', 'user'); // NEU
    }

    /**
     * API: Löscht einen Benutzer. (POST request)
     */
    public function deleteUser()
    {
        Security::requireRole('admin'); // Auth check first
        $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception("Ungültige ID.");
            }
            $this->userRepository->delete($id);
            echo json_encode(['success' => true, 'message' => 'Benutzer erfolgreich gelöscht.'], JSON_THROW_ON_ERROR);

            return ['target_id' => $id];
        }, 'delete_user', 'user'); // NEU
    }

    /**
     * NEU: API: Importiert Benutzer aus einer CSV-Datei.
     * (POST request, multipart/form-data)
     */
    public function importUsers()
    {
        // Dieser Endpunkt verwendet multipart/form-data, daher kein handleApiRequest (oder ein angepasster)
        header('Content-Type: application/json');
        try {
            Security::requireRole('admin');
            Security::verifyCsrfToken(); // Manuelle CSRF-Prüfung (prüft POST-Body)

            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Keine CSV-Datei hochgeladen oder Fehler beim Upload.');
            }

            $tmpFilePath = $_FILES['csv_file']['tmp_name'];
            $fileType = mime_content_type($tmpFilePath);
            $fileExtension = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            // Striktere Prüfung auf CSV-Typen
            if (!in_array($fileType, ['text/plain', 'text/csv', 'application/csv']) && $fileExtension !== 'csv') {
                 throw new Exception('Ungültiger Dateityp. Bitte laden Sie eine CSV-Datei hoch.');
            }

            // Hole Stammdaten für die Validierung
            $validClasses = $this->stammdatenRepository->getClasses();
            $validTeachers = $this->stammdatenRepository->getTeachers();
            $validRoles = $this->userRepository->getAvailableRoles();
            
            $validationData = [
                'class_ids' => array_column($validClasses, 'class_id'),
                'teacher_ids' => array_column($validTeachers, 'teacher_id'),
                'roles' => $validRoles
            ];

            // Führe den Import im Repository durch
            $result = $this->userRepository->importFromCSV($tmpFilePath, $validationData);

            // Protokolliere den Import-Vorgang
            AuditLogger::log(
                'import_users_csv',
                'system',
                null, // Kein spezifisches Zielobjekt
                [
                    'filename' => $_FILES['csv_file']['name'],
                    'success_count' => $result['success_count'],
                    'failure_count' => $result['failure_count'],
                    'errors' => $result['errors'] // Logge die ersten paar Fehler
                ]
            );

            echo json_encode(['success' => true, 'message' => 'Import abgeschlossen.', 'data' => $result]);

        } catch (Exception $e) {
            http_response_code(str_contains($e->getMessage(), 'CSRF') ? 403 : 400);
            error_log("User Import Error: " . $e->getMessage()); // Log des Fehlers
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }
}

