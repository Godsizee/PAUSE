<?php
namespace App\Http\Controllers;
use App\Core\Security;
use App\Core\Utils;
use App\Core\Database;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\StudentNoteRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;
use App\Http\Traits\ApiHandlerTrait;

class DashboardController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private UserRepository $userRepository;
    private PlanRepository $planRepository;
    private AppointmentRepository $appointmentRepo;
    private StudentNoteRepository $noteRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->planRepository = new PlanRepository($this->pdo);
        $this->appointmentRepo = new AppointmentRepository($this->pdo);
        $this->noteRepo = new StudentNoteRepository($this->pdo);
    }

    public function index()
    {
        Security::requireLogin();
        $userId = $_SESSION['user_id'];
        $role = $_SESSION['user_role'] ?? 'Unbekannt';

        global $config;
        $config = Database::getConfig();

        if ($role === 'admin') {
            header("Location: " . Utils::url('admin/dashboard'));
            exit();
        }
        if ($role === 'planer') {
            header("Location: " . Utils::url('planer/dashboard'));
            exit();
        }

        $icalSubscriptionUrl = null;
        $user = null;

        if (in_array($role, ['schueler', 'lehrer'])) {
            try {
                $user = $this->userRepository->findById($userId);
                if ($user) {
                    $token = $this->userRepository->generateOrGetIcalToken($userId);
                    if ($token) {
                        $baseUrl = rtrim($config['base_url'], '/');
                        $icalPath = 'ical/' . $token;
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                        $host = $_SERVER['HTTP_HOST'];
                        $icalSubscriptionUrl = $protocol . $host . Utils::url($icalPath);
                    } else {
                        error_log("Could not generate or get iCal token for user ID: " . $userId);
                    }
                } else {
                    error_log("User not found for ID: " . $userId . " in DashboardController");
                }
            } catch (Exception $e) {
                error_log("Error fetching iCal token: " . $e->getMessage());
            }
        }

        $page_title = 'Mein Stundenplan';
        $body_class = 'dashboard-body';
        $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        $dayOfWeekName = [
            1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'
        ][$today->format('N')] ?? 'Unbekannt';
        $dateFormatted = $today->format('d.m.Y');
        
        require_once dirname(__DIR__, 3) . '/pages/dashboard.php';
    }

    public function getWeeklyData()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $userId = $_SESSION['user_id'];
            $userRole = $_SESSION['user_role'];
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $calendarWeek = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);

            if (!$year || !$calendarWeek) {
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $year = (int)$today->format('o');
                $calendarWeek = (int)$today->format('W');
            }

            $monday = new DateTime();
            $monday->setISODate($year, $calendarWeek, 1);
            $startDate = $monday->format('Y-m-d');
            $sunday = new DateTime();
            $sunday->setISODate($year, $calendarWeek, 7);
            $endDate = $sunday->format('Y-m-d');

            $user = $this->userRepository->findById($userId);
            if (!$user) {
                throw new Exception("Benutzer nicht gefunden.");
            }

            $regularTimetable = [];
            $substitutions = [];
            $appointments = [];
            $notes = [];
            $targetGroup = null;

            if ($userRole === 'schueler' && !empty($user['class_id'])) {
                $targetGroup = 'student';
                if ($this->planRepository->isWeekPublishedFor($targetGroup, $year, $calendarWeek)) {
                    $regularTimetable = $this->planRepository->getPublishedTimetableForClass($user['class_id'], $year, $calendarWeek);
                    $substitutions = $this->planRepository->getPublishedSubstitutionsForClassWeek($user['class_id'], $year, $calendarWeek);
                    $appointments = $this->appointmentRepo->getAppointmentsForStudent($userId, $startDate, $endDate);
                    $notes = $this->noteRepo->getNotesForWeek($userId, $year, $calendarWeek);
                }
            } elseif ($userRole === 'lehrer' && !empty($user['teacher_id'])) {
                $targetGroup = 'teacher';
                if ($this->planRepository->isWeekPublishedFor($targetGroup, $year, $calendarWeek)) {
                    $regularTimetable = $this->planRepository->getPublishedTimetableForTeacher($user['teacher_id'], $year, $calendarWeek);
                    $substitutions = $this->planRepository->getPublishedSubstitutionsForTeacherWeek($user['teacher_id'], $year, $calendarWeek);
                    $appointments = $this->appointmentRepo->getAppointmentsForTeacher($userId, $startDate, $endDate);
                }
            }

            echo json_encode(['success' => true, 'data' => [
                'timetable' => $regularTimetable,
                'substitutions' => $substitutions,
                'appointments' => $appointments,
                'notes' => $notes
            ]]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer', 'planer', 'admin'] // requireLogin
        ]);
    }

    public function saveNoteApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $userId = $_SESSION['user_id'];
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $calendarWeek = filter_var($data['calendar_week'] ?? null, FILTER_VALIDATE_INT);
            $dayOfWeek = filter_var($data['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            $periodNumber = filter_var($data['period_number'] ?? null, FILTER_VALIDATE_INT);
            $content = $data['note_content'] ?? '';

            if (!$year || !$calendarWeek || !$dayOfWeek || !$periodNumber) {
                throw new Exception("Fehlende Kontextdaten (Woche, Tag oder Stunde).", 400);
            }

            $success = $this->noteRepo->saveNote(
                $userId,
                $year,
                $calendarWeek,
                $dayOfWeek,
                $periodNumber,
                $content
            );
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Notiz gespeichert.']);
                return [
                    'log_action' => 'save_student_note',
                    'log_target_type' => 'student_note',
                    'log_details' => [
                        'year' => $year,
                        'week' => $calendarWeek,
                        'day' => $dayOfWeek,
                        'period' => $periodNumber,
                        'action' => empty(trim($content)) ? 'deleted' : 'saved'
                    ]
                ];
            } else {
                throw new Exception("Notiz konnte nicht gespeichert werden.");
            }
        }, [
            'inputType' => 'json',
            'checkRole' => 'schueler'
        ]);
    }

    public function getUpcomingSlotsApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $teacherId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$teacherId) {
                throw new Exception("Ungültige Lehrer-ID.", 400);
            }
            
            $teacherUser = $this->userRepository->findUserByTeacherId($teacherId);
            if (!$teacherUser) {
                throw new Exception("Lehrerprofil (Benutzer) nicht gefunden.", 404);
            }
            $teacherUserId = $teacherUser['user_id'];

            $daysInFuture = 14;
            $slots = $this->appointmentRepo->getUpcomingAvailableSlots($teacherUserId, $daysInFuture);
            
            echo json_encode(['success' => true, 'data' => $slots]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'schueler'
        ]);
    }
    
    public function bookAppointmentApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $studentUserId = $_SESSION['user_id'];
            $teacherId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $availabilityId = filter_var($data['availability_id'] ?? null, FILTER_VALIDATE_INT);
            $date = $data['date'] ?? null;
            $time = $data['time'] ?? null;
            $duration = filter_var($data['duration'] ?? null, FILTER_VALIDATE_INT);
            $location = isset($data['location']) ? trim($data['location']) : null;
            $notes = isset($data['notes']) ? trim($data['notes']) : null;

            if (!$teacherId || !$availabilityId || !$date || !$time || !$duration || $location === null) {
                throw new Exception("Fehlende Daten für die Buchung (Lehrer, Slot, Datum, Zeit, Dauer oder Ort).", 400);
            }
            
            $teacherUser = $this->userRepository->findUserByTeacherId($teacherId);
            if (!$teacherUser) {
                throw new Exception("Lehrerprofil (Benutzer) nicht gefunden.", 404);
            }
            $teacherUserId = $teacherUser['user_id'];
            
            $today = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            if ($date < $today) {
                throw new Exception("Termine können nicht in der Vergangenheit gebucht werden.", 400);
            }

            $newId = $this->appointmentRepo->bookAppointment(
                $studentUserId,
                $teacherUserId,
                $availabilityId,
                $date,
                $time,
                $duration,
                $location,
                $notes
            );
            
            echo json_encode(['success' => true, 'message' => 'Sprechstunde erfolgreich gebucht!']);

            return [
                'log_action' => 'book_appointment',
                'log_target_type' => 'appointment',
                'log_target_id' => $newId,
                'log_details' => [
                    'teacher_user_id' => $teacherUserId,
                    'date' => $date,
                    'time' => $time,
                    'location' => $location
                ]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => 'schueler'
        ]);
    }

    public function cancelAppointmentApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $userId = $_SESSION['user_id'];
            $role = $_SESSION['user_role'];
            $appointmentId = filter_var($data['appointment_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$appointmentId) {
                throw new Exception("Keine Termin-ID angegeben.", 400);
            }

            $success = $this->appointmentRepo->cancelAppointment($appointmentId, $userId, $role);
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Termin erfolgreich storniert.']);
                return [
                    'log_action' => 'cancel_appointment',
                    'log_target_type' => 'appointment',
                    'log_target_id' => $appointmentId,
                    'log_details' => ['cancelled_by_role' => $role]
                ];
            }
            // cancelAppointment wirft bereits eine Exception, wenn es fehlschlägt
        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer'] // requireLogin, aber spezifischer
        ]);
    }
}