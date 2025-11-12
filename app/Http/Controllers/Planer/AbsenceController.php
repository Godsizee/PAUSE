<?php
namespace App\Http\Controllers\Planer;
use App\Core\Database;
use App\Core\Security;
use App\Repositories\TeacherAbsenceRepository;
use App\Repositories\StammdatenRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;
use App\Http\Traits\ApiHandlerTrait; // NEU

class AbsenceController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private TeacherAbsenceRepository $absenceRepo;
    private StammdatenRepository $stammdatenRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->absenceRepo = new TeacherAbsenceRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
    }

    public function index()
    {
        Security::requireRole(['admin', 'planer']);
        global $config;
        $config = Database::getConfig();
        $page_title = 'Lehrer-Abwesenheiten';
        $body_class = 'planer-dashboard-body';
        try {
            $availableTeachers = $this->stammdatenRepo->getTeachers();
            $absenceTypes = $this->absenceRepo->getAbsenceTypes();
            Security::getCsrfToken();
            include_once dirname(__DIR__, 4) .'/pages/planer/absences.php';
        } catch (Exception $e) {
            error_log("Fehler beim Laden der Abwesenheits-Seite: " . $e->getMessage());
            http_response_code(500);
            die("Ein Fehler ist beim Laden der Seite aufgetreten: " . $e->getMessage());
        }
    }

    public function getAbsencesApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $startDate = filter_var($data['start'] ?? null, FILTER_UNSAFE_RAW);
            $endDate = filter_var($data['end'] ?? null, FILTER_UNSAFE_RAW);

            if (!$startDate || !$endDate) {
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $startDate = $today->format('Y-m-01');
                $endDate = $today->format('Y-m-t');
            }
            if (DateTime::createFromFormat('Y-m-d', $startDate) === false || DateTime::createFromFormat('Y-m-d', $endDate) === false) {
                throw new Exception("Ungültiges Datumsformat.", 400);
            }
            $absences = $this->absenceRepo->getAbsencesForPeriod($startDate, $endDate);
            echo json_encode(['success' => true, 'data' => $absences]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    public function saveAbsenceApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $absenceId = filter_var($data['absence_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $teacherId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $startDate = $data['start_date'] ?? null;
            $endDate = $data['end_date'] ?? null;
            $reason = trim($data['reason'] ?? '');
            $comment = isset($data['comment']) ? trim($data['comment']) : null;
            if (!$teacherId || !$startDate || !$endDate || empty($reason)) {
                throw new Exception("Fehlende Daten: Lehrer, Start, Ende und Grund sind erforderlich.", 400);
            }
            if (DateTime::createFromFormat('Y-m-d', $startDate) === false || DateTime::createFromFormat('Y-m-d', $endDate) === false) {
                throw new Exception("Ungültiges Datumsformat.", 400);
            }
            if ($endDate < $startDate) {
                throw new Exception("Enddatum muss nach dem Startdatum liegen.", 400);
            }
            $validTypes = $this->absenceRepo->getAbsenceTypes();
            if (!in_array($reason, $validTypes)) {
                throw new Exception("Ungültiger Abwesenheitsgrund.", 400);
            }
            $savedAbsence = $this->absenceRepo->createAbsence($absenceId, $teacherId, $startDate, $endDate, $reason, $comment);
            $newId = $savedAbsence['absence_id'];

            echo json_encode(['success' => true, 'message' => 'Abwesenheit gespeichert.', 'data' => $savedAbsence]);

            return [
                'log_action' => $absenceId ? 'update_absence' : 'create_absence',
                'log_target_type' => 'teacher_absence',
                'log_target_id' => $newId,
                'log_details' => [
                    'teacher_id' => $teacherId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $reason
                ]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    public function deleteAbsenceApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $absenceId = filter_var($data['absence_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$absenceId) {
                throw new Exception("Fehlende Abwesenheits-ID.", 400);
            }
            $absence = $this->absenceRepo->getAbsenceById($absenceId);
            if (!$absence) {
                throw new Exception("Abwesenheit nicht gefunden.", 404);
            }
            $success = $this->absenceRepo->deleteAbsence($absenceId);
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Abwesenheit gelöscht.']);
                return [
                    'log_action' => 'delete_absence',
                    'log_target_type' => 'teacher_absence',
                    'log_target_id' => $absenceId,
                    'log_details' => [
                        'teacher_id' => $absence['teacher_id'],
                        'reason' => $absence['reason'],
                        'start_date' => $absence['start_date']
                    ]
                ];
            } else {
                throw new Exception("Abwesenheit konnte nicht gelöscht werden.", 500);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }
}