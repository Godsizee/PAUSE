<?php
// app/Http/Controllers/Admin/StammdatenController.php
namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\StammdatenRepository;
use Exception;
use PDO;
use App\Services\AuditLogger; // NEU: AuditLogger importieren

class StammdatenController
{
    private PDO $pdo;
    private StammdatenRepository $repository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->repository = new StammdatenRepository($this->pdo);
    }

    /**
     * Zeigt die Hauptseite für die Stammdatenverwaltung an.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Stammdatenverwaltung';
        $body_class = 'admin-dashboard-body';
         // Ensure CSRF token is generated for forms on this page
        Security::getCsrfToken();

        include_once dirname(__DIR__, 4) . '/pages/admin/stammdaten.php';
    }

    // --- API Helper ---
    /**
     * Helper to wrap common API request logic (auth, CSRF, response type, error handling)
     * @param callable $callback The actual logic to execute
     * @param string $actionName Der Name der Aktion für das Audit-Log (z.B. 'create_subject')
     * @param string $targetType Der Typ des Ziels (z.B. 'subject')
     */
    private function handleApiRequest(callable $callback, string $actionName = '', string $targetType = ''): void
    {
        // Security checks are done within each method that calls this for clarity
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
            http_response_code(str_contains($e->getMessage(), 'CSRF') ? 403 : (str_contains($e->getMessage(), 'existiert bereits') ? 409 : 400));
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }

    // --- API METHODS FOR SUBJECTS ---

    /**
     * API: Holt alle Fächer und gibt sie als JSON zurück. (GET request - no CSRF needed)
     */
    public function getSubjects()
    {
        Security::requireRole('admin');
        header('Content-Type: application/json');
        try {
            $subjects = $this->repository->getSubjects();
            echo json_encode(['success' => true, 'data' => $subjects], JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Fächer: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }

    /**
     * API: Erstellt ein neues Fach. (POST request)
     */
    public function createSubject()
    {
        Security::requireRole('admin'); // Auth check first
        $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $name = trim($_POST['subject_name'] ?? '');
            $shortcut = trim($_POST['subject_shortcut'] ?? '');
            if (empty($name) || empty($shortcut)) {
                throw new Exception("Fachname und Kürzel dürfen nicht leer sein.");
            }
            $newId = $this->repository->createSubject($name, $shortcut);
            $newSubject = ['subject_id' => $newId, 'subject_name' => $name, 'subject_shortcut' => $shortcut];

            echo json_encode(['success' => true, 'message' => 'Fach erfolgreich erstellt.', 'data' => $newSubject], JSON_THROW_ON_ERROR);
            
            // Rückgabe für Audit-Log
            return [
                'target_id' => $newId,
                'details' => ['name' => $name, 'shortcut' => $shortcut]
            ];
        }, 'create_subject', 'subject'); // NEU: Audit-Log Parameter
    }

    /**
     * API: Aktualisiert ein bestehendes Fach. (POST request)
     */
    public function updateSubject()
    {
         Security::requireRole('admin'); // Auth check first
           $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $name = trim($_POST['subject_name'] ?? '');
            $shortcut = trim($_POST['subject_shortcut'] ?? '');

            if (!$id || empty($name) || empty($shortcut)) {
                throw new Exception("Ungültige Daten für das Update.");
            }
            $this->repository->updateSubject($id, $name, $shortcut);
            $updatedSubject = ['subject_id' => $id, 'subject_name' => $name, 'subject_shortcut' => $shortcut];
            echo json_encode(['success' => true, 'message' => 'Fach erfolgreich aktualisiert.', 'data' => $updatedSubject], JSON_THROW_ON_ERROR);

            // Rückgabe für Audit-Log
            return [
                'target_id' => $id,
                'details' => ['name' => $name, 'shortcut' => $shortcut]
            ];
        }, 'update_subject', 'subject'); // NEU: Audit-Log Parameter
    }

    /**
     * API: Löscht ein Fach. (POST request)
     */
    public function deleteSubject()
    {
        Security::requireRole('admin'); // Auth check first
        $this->handleApiRequest(function() { // CSRF check inside handleApiRequest
            $id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception("Ungültige ID.");
            }
            $this->repository->deleteSubject($id);
            echo json_encode(['success' => true, 'message' => 'Fach erfolgreich gelöscht.'], JSON_THROW_ON_ERROR);

            // Rückgabe für Audit-Log
            return [
                'target_id' => $id,
                'details' => ['id' => $id]
            ];
        }, 'delete_subject', 'subject'); // NEU: Audit-Log Parameter
    }

    // --- API METHODS FOR ROOMS ---
    // GET - No CSRF
    public function getRooms() {
        Security::requireRole('admin');
        header('Content-Type: application/json');
        try {
            echo json_encode(['success' => true, 'data' => $this->repository->getRooms()], JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Räume.'], JSON_THROW_ON_ERROR);
        }
        exit();
    }
    // POST - CSRF handled
    public function createRoom() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $name = trim($_POST['room_name'] ?? '');
            if (empty($name)) throw new Exception("Raumname darf nicht leer sein.");
            $newId = $this->repository->createRoom($name); // Assuming createRoom returns ID
            $newRoom = ['room_id' => $newId, 'room_name' => $name];
            echo json_encode(['success' => true, 'message' => 'Raum erfolgreich erstellt.', 'data' => $newRoom], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $newId,
                'details' => ['name' => $name]
            ];
        }, 'create_room', 'room'); // NEU
    }
    // POST - CSRF handled
    public function updateRoom() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
            $name = trim($_POST['room_name'] ?? '');
            if (!$id || empty($name)) throw new Exception("Ungültige Daten.");
            $this->repository->updateRoom($id, $name);
            $updatedRoom = ['room_id' => $id, 'room_name' => $name];
            echo json_encode(['success' => true, 'message' => 'Raum erfolgreich aktualisiert.', 'data' => $updatedRoom], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $id,
                'details' => ['name' => $name]
            ];
        }, 'update_room', 'room'); // NEU
    }
    // POST - CSRF handled
    public function deleteRoom() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteRoom($id);
            echo json_encode(['success' => true, 'message' => 'Raum erfolgreich gelöscht.'], JSON_THROW_ON_ERROR);
            
            return ['target_id' => $id];
        }, 'delete_room', 'room'); // NEU
    }

    // --- API METHODS FOR TEACHERS ---
    // GET - No CSRF
    public function getTeachers() {
        Security::requireRole('admin');
        header('Content-Type: application/json');
        try {
            echo json_encode(['success' => true, 'data' => $this->repository->getTeachers()], JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Lehrer.'], JSON_THROW_ON_ERROR);
        }
        exit();
    }
    // POST - CSRF handled
    public function createTeacher() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $data = [
                'shortcut' => trim($_POST['teacher_shortcut'] ?? ''), // Renamed key to match repo
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'email' => empty(trim($_POST['email'] ?? '')) ? null : trim($_POST['email']) // Handle empty email as NULL
            ];
            if (empty($data['shortcut']) || empty($data['first_name']) || empty($data['last_name'])) {
                throw new Exception("Kürzel, Vorname und Nachname sind Pflichtfelder.");
            }
            $newId = $this->repository->createTeacher($data);
            $newTeacher = array_merge(['teacher_id' => $newId], $data);
            echo json_encode(['success' => true, 'message' => 'Lehrer erfolgreich erstellt.', 'data' => $newTeacher], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $newId,
                'details' => $data
            ];
        }, 'create_teacher', 'teacher'); // NEU
    }
    // POST - CSRF handled
    public function updateTeacher() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            $data = [
                'shortcut' => trim($_POST['teacher_shortcut'] ?? ''), // Renamed key
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'email' => empty(trim($_POST['email'] ?? '')) ? null : trim($_POST['email']) // Handle empty email as NULL
            ];
            if (!$id || empty($data['shortcut']) || empty($data['first_name']) || empty($data['last_name'])) {
                throw new Exception("Ungültige Daten.");
            }
            $this->repository->updateTeacher($id, $data);
            $updatedTeacher = array_merge(['teacher_id' => $id], $data);
            echo json_encode(['success' => true, 'message' => 'Lehrer erfolgreich aktualisiert.', 'data' => $updatedTeacher], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $id,
                'details' => $data
            ];
        }, 'update_teacher', 'teacher'); // NEU
    }
    // POST - CSRF handled
    public function deleteTeacher() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteTeacher($id);
            echo json_encode(['success' => true, 'message' => 'Lehrer erfolgreich gelöscht.'], JSON_THROW_ON_ERROR);
            
            return ['target_id' => $id];
        }, 'delete_teacher', 'teacher'); // NEU
    }

    // --- API METHODS FOR CLASSES ---
    // GET - No CSRF
    public function getClasses() {
        Security::requireRole('admin');
        header('Content-Type: application/json');
        try {
            echo json_encode(['success' => true, 'data' => $this->repository->getClasses()], JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Klassen.'], JSON_THROW_ON_ERROR);
        }
        exit();
    }
    // POST - CSRF handled
    public function createClass() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            // Retrieve class_id from POST data, not use it as primary key directly in create if auto-increment
            $id = filter_input(INPUT_POST, 'class_id_input', FILTER_VALIDATE_INT); // This is the user-defined ID
            $name = trim($_POST['class_name'] ?? '');
            $teacherId = filter_input(INPUT_POST, 'class_teacher_id', FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId; // Handle '0' or empty value correctly

            if (empty($name) || !$id || $id <= 0) { // Also check if ID is positive
                 throw new Exception("Klassen-ID (positiv) und Klassenname dürfen nicht leer sein.");
            }

            // The repository method handles the ID check and insertion
            $this->repository->createClass($id, $name, $teacherId);
            $newClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId]; // Return the data
             // Optionally fetch teacher name here if needed in frontend response
            echo json_encode(['success' => true, 'message' => 'Klasse erfolgreich erstellt.', 'data' => $newClass], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $id,
                'details' => ['name' => $name, 'teacher_id' => $teacherId]
            ];
        }, 'create_class', 'class'); // NEU
    }
    // POST - CSRF handled
    public function updateClass() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'class_id_hidden', FILTER_VALIDATE_INT); // ID comes from the hidden input/data attribute now
            $name = trim($_POST['class_name'] ?? '');
            $teacherId = filter_input(INPUT_POST, 'class_teacher_id', FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId; // Handle '0' or empty value correctly

            if (!$id || empty($name)) {
                 throw new Exception("Ungültige Daten für Update (ID und Name benötigt).");
            }
            $this->repository->updateClass($id, $name, $teacherId);
            $updatedClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId];
             // Optionally fetch teacher name here
            echo json_encode(['success' => true, 'message' => 'Klasse erfolgreich aktualisiert.', 'data' => $updatedClass], JSON_THROW_ON_ERROR);
            
            return [
                'target_id' => $id,
                'details' => ['name' => $name, 'teacher_id' => $teacherId]
            ];
        }, 'update_class', 'class'); // NEU
    }
    // POST - CSRF handled
    public function deleteClass() {
        Security::requireRole('admin');
        $this->handleApiRequest(function() {
            $id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteClass($id);
            echo json_encode(['success' => true, 'message' => 'Klasse erfolgreich gelöscht.'], JSON_THROW_ON_ERROR);
            
            return ['target_id' => $id];
        }, 'delete_class', 'class'); // NEU
    }
}
