<?php
// app/Http/Controllers/Admin/StammdatenController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. Die lokale Implementierung von handleApiRequest() wurde entfernt.
// 3. Alle API-Methoden (get/create/update/delete für alle Typen) wurden
//    vollständig refaktorisiert, um die Trait-Methode zu nutzen.
// 4. 'inputType' => 'get' für Lesezugriffe, 'inputType' => 'form' für Schreibzugriffe.
// 5. Alle Callbacks geben jetzt das vom Trait erwartete Array-Format zurück
//    (inkl. 'json_response', 'log_action', 'log_target_id' etc.).

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\StammdatenRepository;
use Exception;
use PDO;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren

class StammdatenController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

    private PDO $pdo;
    private StammdatenRepository $repository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->repository = new StammdatenRepository($this->pdo);
    }

    /**
     * Zeigt die Hauptseite für die Stammdatenverwaltung an.
     * (Unverändert)
     */
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

    // --- API METHODS FOR SUBJECTS ---

    /**
     * API: Holt alle Fächer und gibt sie als JSON zurück.
     * MODIFIZIERT: Nutzt ApiHandlerTrait.
     */
    public function getSubjects()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            $subjects = $this->repository->getSubjects();
            
            return [
                'json_response' => ['success' => true, 'data' => $subjects]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Erstellt ein neues Fach.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_POST.
     */
    public function createSubject()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $name = trim($data['subject_name'] ?? '');
            $shortcut = trim($data['subject_shortcut'] ?? '');
            if (empty($name) || empty($shortcut)) {
                throw new Exception("Fachname und Kürzel dürfen nicht leer sein.", 400);
            }
            $newId = $this->repository->createSubject($name, $shortcut);
            $newSubject = ['subject_id' => $newId, 'subject_name' => $name, 'subject_shortcut' => $shortcut];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Fach erfolgreich erstellt.', 'data' => $newSubject],
                'log_action' => 'create_subject',
                'log_target_type' => 'subject',
                'log_target_id' => $newId,
                'log_details' => ['name' => $name, 'shortcut' => $shortcut]
            ];

        }, [
            'inputType' => 'form', // JS sendet FormData
            'checkRole' => 'admin'
        ]);
    }

    /**
     * API: Aktualisiert ein bestehendes Fach.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_POST.
     */
    public function updateSubject()
    {
         $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $id = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($data['subject_name'] ?? '');
            $shortcut = trim($data['subject_shortcut'] ?? '');

            if (!$id || empty($name) || empty($shortcut)) {
                throw new Exception("Ungültige Daten für das Update.", 400);
            }
            $this->repository->updateSubject($id, $name, $shortcut);
            $updatedSubject = ['subject_id' => $id, 'subject_name' => $name, 'subject_shortcut' => $shortcut];

            // Rückgabe für Trait
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

    /**
     * API: Löscht ein Fach.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_POST.
     */
    public function deleteSubject()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $id = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new Exception("Ungültige ID.", 400);
            }

            // Hole Daten für Log VOR dem Löschen (optional, aber gut für Details)
            // $subject = $this->repository->getSubjectById($id); // Annahme: getSubjectById existiert
            // $details = ['name' => $subject['subject_name'] ?? 'N/A'];
            
            $this->repository->deleteSubject($id);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Fach erfolgreich gelöscht.'],
                'log_action' => 'delete_subject',
                'log_target_type' => 'subject',
                'log_target_id' => $id,
                'log_details' => ['id' => $id] // $details
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    // --- API METHODS FOR ROOMS ---

    public function getRooms() {
        $this->handleApiRequest(function($data) {
            $rooms = $this->repository->getRooms();
            return [
                'json_response' => ['success' => true, 'data' => $rooms]
            ];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createRoom() {
        $this->handleApiRequest(function($data) {
            $name = trim($data['room_name'] ?? '');
            if (empty($name)) throw new Exception("Raumname darf nicht leer sein.", 400);
            
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
            if (!$id || empty($name)) throw new Exception("Ungültige Daten.", 400);
            
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
            if (!$id) throw new Exception("Ungültige ID.", 400);
            
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

    // --- API METHODS FOR TEACHERS ---

    public function getTeachers() {
        $this->handleApiRequest(function($data) {
            $teachers = $this->repository->getTeachers();
            return [
                'json_response' => ['success' => true, 'data' => $teachers]
            ];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createTeacher() {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            $teacherData = [
                'shortcut' => trim($data['teacher_shortcut'] ?? ''),
                'first_name' => trim($data['first_name'] ?? ''),
                'last_name' => trim($data['last_name'] ?? ''),
                'email' => empty(trim($data['email'] ?? '')) ? null : trim($data['email'])
            ];

            if (empty($teacherData['shortcut']) || empty($teacherData['first_name']) || empty($teacherData['last_name'])) {
                throw new Exception("Kürzel, Vorname und Nachname sind Pflichtfelder.", 400);
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
        $this->handleApiRequest(function($data) { // $data ist $_POST
            $id = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherData = [
                'shortcut' => trim($data['teacher_shortcut'] ?? ''),
                'first_name' => trim($data['first_name'] ?? ''),
                'last_name' => trim($data['last_name'] ?? ''),
                'email' => empty(trim($data['email'] ?? '')) ? null : trim($data['email'])
            ];

            if (!$id || empty($teacherData['shortcut']) || empty($teacherData['first_name']) || empty($teacherData['last_name'])) {
                throw new Exception("Ungültige Daten.", 400);
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
        $this->handleApiRequest(function($data) { // $data ist $_POST
            $id = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.", 400);
            
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

    // --- API METHODS FOR CLASSES ---

    public function getClasses() {
        $this->handleApiRequest(function($data) {
            $classes = $this->repository->getClasses();
            return [
                'json_response' => ['success' => true, 'data' => $classes]
            ];
        }, [
            'inputType' => 'get',
            'checkRole' => 'admin'
        ]);
    }

    public function createClass() {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            // KORREKTUR: JS sendet 'class_id_input'
            $id = filter_var($data['class_id_input'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($data['class_name'] ?? '');
            $teacherId = filter_var($data['class_teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId;

            if (empty($name) || !$id || $id <= 0) {
                 throw new Exception("Klassen-ID (positiv) und Klassenname dürfen nicht leer sein.", 400);
            }

            // Repository wirft Exception bei Duplikat
            $this->repository->createClass($id, $name, $teacherId);
            $newClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId];
            
            // Log-Details
            $logDetails = ['name' => $name, 'teacher_id' => $teacherId];

            return [
                'json_response' => ['success' => true, 'message' => 'Klasse erfolgreich erstellt.', 'data' => $newClass],
                'log_action' => 'create_class',
                'log_target_type' => 'class',
                'log_target_id' => $id,
                'log_details' => $logDetails
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function updateClass() {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            // KORREKTUR: JS sendet 'class_id_hidden'
            $id = filter_var($data['class_id_hidden'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($data['class_name'] ?? '');
            $teacherId = filter_var($data['class_teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherId = ($teacherId === 0 || $teacherId === false) ? null : $teacherId;

            if (!$id || empty($name)) {
                 throw new Exception("Ungültige Daten für Update (ID und Name benötigt).", 400);
            }
            
            $this->repository->updateClass($id, $name, $teacherId);
            $updatedClass = ['class_id' => $id, 'class_name' => $name, 'class_teacher_id' => $teacherId];

            // Log-Details
            $logDetails = ['name' => $name, 'teacher_id' => $teacherId];

            return [
                'json_response' => ['success' => true, 'message' => 'Klasse erfolgreich aktualisiert.', 'data' => $updatedClass],
                'log_action' => 'update_class',
                'log_target_type' => 'class',
                'log_target_id' => $id,
                'log_details' => $logDetails
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function deleteClass() {
        $this->handleApiRequest(function($data) { // $data ist $_POST
            
            $id = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new Exception("Ungültige ID.", 400);
            
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