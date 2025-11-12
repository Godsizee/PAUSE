<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\AcademicEventRepository;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;
use App\Http\Traits\ApiHandlerTrait; // NEU

class AcademicEventController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private AcademicEventRepository $eventRepo;
    private UserRepository $userRepo;
    private StammdatenRepository $stammdatenRepo;
    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->eventRepo = new AcademicEventRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
    }

    public function getForStudent()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $userId = $_SESSION['user_id'];
            $user = $this->userRepo->findById($userId);
            if (!$user || !$user['class_id']) {
                throw new Exception("Schülerdaten unvollständig (Klasse fehlt).", 400);
            }
            $classId = $user['class_id'];
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            if (!$year || !$week) {
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $year = (int)$today->format('o');
                $week = (int)$today->format('W');
            }
            $events = $this->eventRepo->getEventsForClassByWeek($classId, $year, $week);
            echo json_encode(['success' => true, 'data' => $events]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'schueler'
        ]);
    }

    public function getForTeacher()
    {
        $this->handleApiRequest(function($data) {
            $userId = $_SESSION['user_id'];
            $daysInFuture = 14;
            $events = $this->eventRepo->getEventsByTeacher($userId, $daysInFuture);
            echo json_encode(['success' => true, 'data' => $events]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    public function createOrUpdate()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $userId = $_SESSION['user_id'];
            $user = $this->userRepo->findById($userId);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Lehrerprofil nicht gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];
            
            $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $classId = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $eventType = $data['event_type'] ?? null;
            $title = trim($data['title'] ?? '');
            $dueDate = $data['due_date'] ?? null;
            $description = isset($data['description']) ? trim($data['description']) : null;
            if (!$classId || !$eventType || empty($title) || !$dueDate || !in_array($eventType, ['aufgabe', 'klausur', 'info'])) {
                throw new Exception("Fehlende oder ungültige Pflichtfelder (Typ, Klasse, Titel, Datum).", 400);
            }
            if (DateTime::createFromFormat('Y-m-d', $dueDate) === false) {
                throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.", 400);
            }
            if (!$this->eventRepo->checkTeacherAuthorization($teacherId, $classId, $dueDate)) {
                error_log("Hinweis: Lehrer {$userId} erstellt Event für Klasse {$classId} an Datum {$dueDate} ohne expliziten Unterrichtsnachweis.");
            }
            $savedEvent = $this->eventRepo->saveEvent(
                $eventId,
                $userId,
                $classId,
                $subjectId,
                $eventType,
                $title,
                $dueDate,
                $description
            );
            $action = $eventId ? 'update_event' : 'create_event';
            
            echo json_encode([
                'success' => true,
                'message' => 'Eintrag erfolgreich ' . ($eventId ? 'aktualisiert' : 'erstellt') . '.',
                'data' => $savedEvent
            ]);

            return [
                'log_action' => $action,
                'log_target_type' => 'academic_event',
                'log_target_id' => $savedEvent['event_id'],
                'log_details' => [
                    'type' => $eventType,
                    'title' => $title,
                    'class_id' => $classId,
                    'due_date' => $dueDate
                ]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }

    public function delete()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $userId = $_SESSION['user_id'];
            $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$eventId) {
                throw new Exception("Keine Event-ID angegeben.", 400);
            }
            $eventToDelete = $this->eventRepo->getEventById($eventId);
            $success = $this->eventRepo->deleteEvent($eventId, $userId);
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Eintrag erfolgreich gelöscht.']);
                return [
                    'log_action' => 'delete_event',
                    'log_target_type' => 'academic_event',
                    'log_target_id' => $eventId,
                    'log_details' => [
                        'title' => $eventToDelete['title'] ?? 'N/A',
                        'type' => $eventToDelete['event_type'] ?? 'N/A',
                        'class_id' => $eventToDelete['class_id'] ?? 'N/A',
                        'due_date' => $eventToDelete['due_date'] ?? 'N/A'
                    ]
                ];
            } else {
                throw new Exception("Eintrag konnte nicht gelöscht werden (nicht gefunden oder keine Berechtigung).", 404);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }
}