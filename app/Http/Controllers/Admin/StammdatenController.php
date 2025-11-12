<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use App\Repositories\StammdatenRepository;
use Exception;
use PDO;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait; // NEU

class StammdatenController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private StammdatenRepository $repository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->repository = new StammdatenRepository($this->pdo);
    }

    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();
        $page_title = 'Stammdatenverwaltung';
        $body_class = 'admin-dashboard-body';
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/admin/stammdaten.php';
    }

    // ENTFERNT: Lokale handleApiRequest Methode

    public function getSubjects()
    {
        $this->handleApiRequest(function($data) {
            $subjects = $this->repository->getSubjects();
            echo json_encode(['success' => true, 'data' => $subjects], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createSubject()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_POST
            $name = trim($data['subject_name'] ?? '');
            $shortcut = trim($data['subject_shortcut'] ?? '');
            if (empty($name) || empty($shortcut)) {
                throw new Exception("Fachname und Kürzel dürfen nicht leer sein.");
            }
            $newId = $this->repository->createSubject($name, $shortcut);
            $newSubject = ['subject_id' => $newId, 'subject_name' => $name, 'subject_shortcut' => $shortcut];

            return [
                'json_response' => ['success' => true, 'message' => 'Fach erfolgreich erstellt.', 'data' => $newSubject],
                'log_action' => 'create_subject',
                'log_target_type' => 'subject',
                'log_target_id' => $newId,
                'log_details' => ['name' => $name, 'shortcut' => $shortcut]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function updateSubject()
    {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($data['subject_name'] ?? '');
            $shortcut = trim($data['subject_shortcut'] ?? '');
            if (!$id || empty($name) || empty($shortcut)) {
                throw new Exception("Ungültige Daten für das Update.");
            }
            $this->repository->updateSubject($id, $name, $shortcut);
            $updatedSubject = ['subject_id' => $id, 'subject_name' => $name, 'subject_shortcut' => $shortcut];

            return [
                'json_response' => ['success' => true, 'message' => 'Fach erfolgreich aktualisiert.', 'data' => $updatedSubject],
                'log_action' => 'update_subject',
                'log_target_type' => 'subject',
                'log_target_id' => $id,
                'log_details' => ['name' => $name, 'shortcut' => $shortcut]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteSubject()
    {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception("Ungültige ID.");
            }
            $this->repository->deleteSubject($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Fach erfolgreich gelöscht.'],
                'log_action' => 'delete_subject',
                'log_target_type' => 'subject',
                'log_target_id' => $id,
                'log_details' => ['id' => $id]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function getRooms() {
        $this->handleApiRequest(function($data) {
            echo json_encode(['success' => true, 'data' => $this->repository->getRooms()], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createRoom() {
        $this->handleApiRequest(function($data) {
            $name = trim($data['room_name'] ?? '');
            if (empty($name)) throw new Exception("Raumname darf nicht leer sein.");
            $newId = $this->repository->createRoom($name);
            $newRoom = ['room_id' => $newId, 'room_name' => $name];

            return [
                'json_response' => ['success' => true, 'message' => 'Raum erfolgreich erstellt.', 'data' => $newRoom],
                'log_action' => 'create_room',
                'log_target_type' => 'room',
                'log_target_id' => $newId,
                'log_details' => ['name' => $name]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function updateRoom() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['room_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($data['room_name'] ?? '');
            if (!$id || empty($name)) throw new Exception("Ungültige Daten.");
            $this->repository->updateRoom($id, $name);
            $updatedRoom = ['room_id' => $id, 'room_name' => $name];

            return [
                'json_response' => ['success' => true, 'message' => 'Raum erfolgreich aktualisiert.', 'data' => $updatedRoom],
                'log_action' => 'update_room',
                'log_target_type' => 'room',
                'log_target_id' => $id,
                'log_details' => ['name' => $name]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteRoom() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['room_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteRoom($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Raum erfolgreich gelöscht.'],
                'log_action' => 'delete_room',
                'log_target_type' => 'room',
                'log_target_id' => $id
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function getTeachers() {
        $this->handleApiRequest(function($data) {
            echo json_encode(['success' => true, 'data' => $this->repository->getTeachers()], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createTeacher() {
        $this->handleApiRequest(function($data) {
            $teacherData = [
                'shortcut' => trim($data['teacher_shortcut'] ?? ''),
                'first_name' => trim($data['first_name'] ?? ''),
                'last_name' => trim($data['last_name'] ?? ''),
                'email' => empty(trim($data['email'] ?? '')) ? null : trim($data['email'])
            ];
            if (empty($teacherData['shortcut']) || empty($teacherData['first_name']) || empty($teacherData['last_name'])) {
                throw new Exception("Kürzel, Vorname und Nachname sind Pflichtfelder.");
            }
            $newId = $this->repository->createTeacher($teacherData);
            $newTeacher = array_merge(['teacher_id' => $newId], $teacherData);

            return [
                'json_response' => ['success' => true, 'message' => 'Lehrer erfolgreich erstellt.', 'data' => $newTeacher],
                'log_action' => 'create_teacher',
                'log_target_type' => 'teacher',
                'log_target_id' => $newId,
                'log_details' => $teacherData
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function updateTeacher() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherData = [
                'shortcut' => trim($data['teacher_shortcut'] ?? ''),
                'first_name' => trim($data['first_name'] ?? ''),
                'last_name' => trim($data['last_name'] ?? ''),
                'email' => empty(trim($data['email'] ?? '')) ? null : trim($data['email'])
            ];
            if (!$id || empty($teacherData['shortcut']) || empty($teacherData['first_name']) || empty($teacherData['last_name'])) {
                throw new Exception("Ungültige Daten.");
            }
            $this->repository->updateTeacher($id, $teacherData);
            $updatedTeacher = array_merge(['teacher_id' => $id], $teacherData);

            return [
                'json_response' => ['success' => true, 'message' => 'Lehrer erfolgreich aktualisiert.', 'data' => $updatedTeacher],
                'log_action' => 'update_teacher',
                'log_target_type' => 'teacher',
                'log_target_id' => $id,
                'log_details' => $teacherData
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteTeacher() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteTeacher($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Lehrer erfolgreich gelöscht.'],
                'log_action' => 'delete_teacher',
                'log_target_type' => 'teacher',
                'log_target_id' => $id
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function getClasses() {
        $this->handleApiRequest(function($data) {
            echo json_encode(['success' => true, 'data' => $this->repository->getClasses()], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createClass() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['class_id_input'] ?? null, FILTER_VALIDATE_INT); // Vom Formular
            $name = trim($data['class_name'] ?? '');
            $teacherId = filter_var($data['class_teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId;
            if (empty($name) || !$id || $id <= 0) {
                throw new Exception("Klassen-ID (positiv) und Klassenname dürfen nicht leer sein.");
            }
            $this->repository->createClass($id, $name, $teacherId);
            $newClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId];

            return [
                'json_response' => ['success' => true, 'message' => 'Klasse erfolgreich erstellt.', 'data' => $newClass],
                'log_action' => 'create_class',
                'log_target_type' => 'class',
                'log_target_id' => $id,
                'log_details' => ['name' => $name, 'teacher_id' => $teacherId]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function updateClass() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['class_id_hidden'] ?? null, FILTER_VALIDATE_INT); // Vom Formular
            $name = trim($data['class_name'] ?? '');
            $teacherId = filter_var($data['class_teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId;
            if (!$id || empty($name)) {
                throw new Exception("Ungültige Daten für Update (ID und Name benötigt).");
            }
            $this->repository->updateClass($id, $name, $teacherId);
            $updatedClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId];

            return [
                'json_response' => ['success' => true, 'message' => 'Klasse erfolgreich aktualisiert.', 'data' => $updatedClass],
                'log_action' => 'update_class',
                'log_target_type' => 'class',
                'log_target_id' => $id,
                'log_details' => ['name' => $name, 'teacher_id' => $teacherId]
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteClass() {
        $this->handleApiRequest(function($data) {
            $id = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.");
            $this->repository->deleteClass($id);

            return [
                'json_response' => ['success' => true, 'message' => 'Klasse erfolgreich gelöscht.'],
                'log_action' => 'delete_class',
                'log_target_type' => 'class',
                'log_target_id' => $id
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }
}