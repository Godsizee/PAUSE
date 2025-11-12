<?php
namespace App\Http\Controllers;
use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\StammdatenRepository;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\AppointmentRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;
use App\Http\Traits\ApiHandlerTrait;

class TeacherController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private StammdatenRepository $stammdatenRepo;
    private PlanRepository $planRepo;
    private UserRepository $userRepo;
    private AttendanceRepository $attendanceRepo;
    private AppointmentRepository $appointmentRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->attendanceRepo = new AttendanceRepository($this->pdo);
        $this->appointmentRepo = new AppointmentRepository($this->pdo);
    }

    public function searchColleaguesApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $query = filter_var($data['query'] ?? '', FILTER_UNSAFE_RAW);
            
            $allTeachers = $this->stammdatenRepo->getTeachers();
            $filteredTeachers = [];

            if (!empty($query)) {
                $filteredTeachers = array_filter($allTeachers, function($teacher) use ($query) {
                    $fullName = $teacher['first_name'] . ' ' . $teacher['last_name'];
                    return stripos($fullName, $query) !== false ||
                           stripos($teacher['teacher_shortcut'], $query) !== false;
                });
            } else {
                $filteredTeachers = array_slice($allTeachers, 0, 10);
            }
            
            echo json_encode(['success' => true, 'data' => array_values($filteredTeachers)]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['lehrer', 'schueler']
        ]);
    }

    public function findColleagueApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $teacherId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$teacherId) {
                throw new Exception("Keine Lehrer-ID angegeben.", 400);
            }
            
            $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
            $currentDate = $now->format('Y-m-d');
            $currentYear = (int)$now->format('o');
            $currentWeek = (int)$now->format('W');
            $currentDayOfWeek = (int)$now->format('N');
            $currentHourMinute = (int)$now->format('Hi');
            $currentPeriod = $this->getCurrentPeriod($currentHourMinute);

            if ($currentDayOfWeek > 5 || $currentPeriod === null) {
                echo json_encode(['success' => true, 'data' => [
                    'status' => 'Außerhalb der Zeit',
                    'message' => 'Der Kollege befindet sich wahrscheinlich nicht im Unterricht (Wochenende oder außerhalb der Unterrichtszeit).'
                ]]);
                return ['is_get_request' => true];
            }

            $lessonInfo = $this->planRepo->findTeacherLocation(
                $teacherId,
                $currentDate,
                $currentYear,
                $currentWeek,
                $currentDayOfWeek,
                $currentPeriod
            );

            $message = $this->formatLessonInfo($lessonInfo);
            
            echo json_encode(['success' => true, 'data' => [
                'status' => $lessonInfo['status'],
                'message' => $message,
                'details' => $lessonInfo['data'] ?? null
            ]]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    public function getCurrentLessonWithStudentsApi()
    {
        $this->handleApiRequest(function($data) {
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];

            $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
            $currentDate = $now->format('Y-m-d');
            $currentYear = (int)$now->format('o');
            $currentWeek = (int)$now->format('W');
            $currentDayOfWeek = (int)$now->format('N');
            $currentHourMinute = (int)$now->format('Hi');
            $currentPeriod = $this->getCurrentPeriod($currentHourMinute);

            if ($currentDayOfWeek > 5 || $currentPeriod === null) {
                echo json_encode(['success' => true, 'data' => ['status' => 'Außerhalb der Zeit', 'lesson' => null, 'students' => []]]);
                return ['is_get_request' => true];
            }

            $lessonInfo = $this->planRepo->findTeacherLocation(
                $teacherId,
                $currentDate,
                $currentYear,
                $currentWeek,
                $currentDayOfWeek,
                $currentPeriod
            );

            if ($lessonInfo['status'] === 'Unterricht' || $lessonInfo['status'] === 'Vertretung') {
                $lessonData = $lessonInfo['data'];
                $classId = $lessonData['class_id'];
                $students = $this->userRepo->getStudentsByClassId($classId);
                $attendance = $this->attendanceRepo->getAttendance($classId, $currentDate, $currentPeriod);
                
                echo json_encode(['success' => true, 'data' => [
                    'status' => $lessonInfo['status'],
                    'lesson' => $lessonData,
                    'students' => $students,
                    'attendance' => $attendance,
                    'context' => ['date' => $currentDate, 'period' => $currentPeriod]
                ]]);
            } else {
                echo json_encode(['success' => true, 'data' => ['status' => $lessonInfo['status'], 'lesson' => null, 'students' => []]]);
            }
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    public function saveAttendanceApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherUserId = $user['user_id'];
            
            $classId = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            $date = $data['date'] ?? null;
            $period = filter_var($data['period_number'] ?? null, FILTER_VALIDATE_INT);
            $students = $data['students'] ?? [];

            if (!$classId || !$date || !$period || empty($students) || !is_array($students)) {
                throw new Exception("Fehlende oder ungültige Daten (Klasse, Datum, Stunde oder Schülerliste).", 400);
            }

            $success = $this->attendanceRepo->saveAttendance(
                $teacherUserId,
                $classId,
                $date,
                $period,
                $students
            );
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Anwesenheit gespeichert!']);
                return [
                    'log_action' => 'save_attendance',
                    'log_target_type' => 'class',
                    'log_target_id' => $classId,
                    'log_details' => [
                        'date' => $date,
                        'period' => $period,
                        'student_count' => count($students)
                    ]
                ];
            } else {
                throw new Exception("Anwesenheit konnte nicht gespeichert werden.");
            }
        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }
    
    public function getPrerequisitesApi()
    {
        $this->handleApiRequest(function($data) {
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];

            $subjects = $this->stammdatenRepo->getSubjects();
            $classes = $this->planRepo->getClassesForTeacher($teacherId);

            echo json_encode([
                'success' => true,
                'data' => [
                    'subjects' => $subjects,
                    'classes' => $classes
                ]
            ]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    public function getOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) {
            $teacherUserId = $_SESSION['user_id'];
            $availabilities = $this->appointmentRepo->getAvailabilities($teacherUserId);
            echo json_encode(['success' => true, 'data' => $availabilities]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    public function saveOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $teacherUserId = $_SESSION['user_id'];
            $dayOfWeek = filter_var($data['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            $startTime = $data['start_time'] ?? null;
            $endTime = $data['end_time'] ?? null;
            $slotDuration = filter_var($data['slot_duration'] ?? 15, FILTER_VALIDATE_INT);
            $location = isset($data['location']) ? trim($data['location']) : null;

            if (!$dayOfWeek || !$startTime || !$endTime || !$slotDuration || $dayOfWeek < 1 || $dayOfWeek > 5 || $slotDuration < 5 || empty($location)) {
                throw new Exception("Ungültige Eingabedaten. Alle Felder (Tag, Zeiten, Dauer, Ort) sind erforderlich.", 400);
            }

            $newId = $this->appointmentRepo->createAvailability($teacherUserId, $dayOfWeek, $startTime, $endTime, $slotDuration, $location);
            
            echo json_encode(['success' => true, 'message' => 'Sprechzeit erfolgreich gespeichert.', 'data' => ['availability_id' => $newId]]);

            return [
                'log_action' => 'create_office_hours',
                'log_target_type' => 'teacher_availability',
                'log_target_id' => $newId,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }

    public function deleteOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $teacherUserId = $_SESSION['user_id'];
            $availabilityId = filter_var($data['availability_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$availabilityId) {
                throw new Exception("Keine ID angegeben.", 400);
            }

            $success = $this->appointmentRepo->deleteAvailability($availabilityId, $teacherUserId);
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Sprechzeit erfolgreich gelöscht.']);
                return [
                    'log_action' => 'delete_office_hours',
                    'log_target_type' => 'teacher_availability',
                    'log_target_id' => $availabilityId
                ];
            } else {
                throw new Exception("Sprechzeit nicht gefunden oder keine Berechtigung.", 404);
            }
        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }

    public function getAllAppointmentsApi()
    {
        $this->handleApiRequest(function($data) {
            $teacherUserId = $_SESSION['user_id'];
            $now = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
            
            // Zukünftige/Heutige Termine (Neueste zuerst -> DESC)
            $allAppointments = $this->appointmentRepo->getAllAppointmentsForTeacher($teacherUserId, 'DESC');
            
            $futureAppointments = array_filter($allAppointments, fn($app) => $app['appointment_date'] . ' ' . $app['appointment_time'] >= $now);
            $pastAppointments = array_filter($allAppointments, fn($app) => $app['appointment_date'] . ' ' . $app['appointment_time'] < $now);

            echo json_encode(['success' => true, 'data' => [
                'future' => array_values($futureAppointments),
                'past' => array_values($pastAppointments)
            ]]);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    private function getCurrentPeriod(int $hourMinute): ?int
    {
        if ($hourMinute >= 800 && $hourMinute <= 845) return 1;
        if ($hourMinute >= 855 && $hourMinute <= 940) return 2;
        if ($hourMinute >= 940 && $hourMinute <= 1025) return 3;
        if ($hourMinute >= 1035 && $hourMinute <= 1120) return 4;
        if ($hourMinute >= 1120 && $hourMinute <= 1205) return 5;
        if ($hourMinute >= 1305 && $hourMinute <= 1350) return 6;
        if ($hourMinute >= 1350 && $hourMinute <= 1435) return 7;
        if ($hourMinute >= 1445 && $hourMinute <= 1530) return 8;
        if ($hourMinute >= 1530 && $hourMinute <= 1615) return 9;
        if ($hourMinute >= 1625 && $hourMinute <= 1710) return 10;
        return null;
    }

    private function formatLessonInfo(array $info): string
    {
        $data = $info['data'] ?? null;
        switch ($info['status']) {
            case 'Unterricht':
                return sprintf(
                    "Hält regulären Unterricht (%s) in Klasse %s in Raum %s.",
                    htmlspecialchars($data['subject_shortcut'] ?? '?'),
                    htmlspecialchars($data['class_name'] ?? '?'),
                    htmlspecialchars($data['room_name'] ?? '?')
                );
            case 'Vertretung':
                return sprintf(
                    "Ist als Vertretung (%s) in Klasse %s in Raum %s.",
                    htmlspecialchars($data['new_subject_shortcut'] ?? '?'),
                    htmlspecialchars($data['class_name'] ?? '?'),
                    htmlspecialchars($data['new_room_name'] ?? '?')
                );
            case 'Entfall':
                return sprintf(
                    "Die Stunde (%s, Klasse %s) entfällt. Der Kollege ist voraussichtlich frei.",
                    htmlspecialchars($data['original_subject_shortcut'] ?? '?'),
                    htmlspecialchars($data['class_name'] ?? '?')
                );
            case 'Raumänderung':
                return sprintf(
                    "Die Stunde (%s, Klasse %s) wurde nach Raum %s verlegt.",
                    htmlspecialchars($data['original_subject_shortcut'] ?? '?'),
                    htmlspecialchars($data['class_name'] ?? '?'),
                    htmlspecialchars($data['new_room_name'] ?? '?')
                );
            case 'Sonderevent':
                return sprintf(
                    "Nimmt am Sonderevent '%s' (Klasse %s) in Raum %s teil.",
                    htmlspecialchars($data['comment'] ?? 'Event'),
                    htmlspecialchars($data['class_name'] ?? '?'),
                    htmlspecialchars($data['new_room_name'] ?? '?')
                );
            case 'Freistunde':
                return "Hat laut Plan jetzt eine Freistunde (FU).";
            default:
                return "Status unbekannt.";
        }
    }
}