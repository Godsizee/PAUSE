<?php
// app/Http/Controllers/TeacherController.php

// MODIFIZIERT:
// 1. AppointmentService importiert und im Konstruktor injiziert.
// 2. AppointmentRepository entfernt (wird jetzt vom Service verwaltet).
// 3. Methoden getOfficeHoursApi, saveOfficeHoursApi, deleteOfficeHoursApi
//    refaktorisiert, um den AppointmentService zu nutzen.
// 4. Geschäftslogik und Validierung aus den Callbacks entfernt und
//    in den Service verschoben.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\StammdatenRepository;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\AttendanceRepository;
use App\Services\AppointmentService; // NEU
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

class TeacherController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private StammdatenRepository $stammdatenRepo;
    private PlanRepository $planRepo;
    private UserRepository $userRepo;
    private AttendanceRepository $attendanceRepo;
    private AppointmentService $appointmentService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->attendanceRepo = new AttendanceRepository($this->pdo);
        
        // NEU: AppointmentService instanziieren (benötigt Repos)
        $this->appointmentService = new AppointmentService(
            new \App\Repositories\AppointmentRepository($this->pdo), // Repository hier übergeben
            $this->userRepo
        );
    }

    /**
     * API: Sucht nach Lehrern (für Schüler oder Lehrer).
     * (Unverändert)
     */
    public function searchColleaguesApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
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

            return [
                'json_response' => ['success' => true, 'data' => array_values($filteredTeachers)]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['lehrer', 'schueler']
        ]);
    }

    /**
     * API: Findet den aktuellen Aufenthaltsort (Stunde/Raum) eines Lehrers.
     * (Unverändert)
     */
    public function findColleagueApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
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
                return [
                    'json_response' => ['success' => true, 'data' => [
                        'status' => 'Außerhalb der Zeit',
                        'message' => 'Der Kollege befindet sich wahrscheinlich nicht im Unterricht (Wochenende oder außerhalb der Unterrichtszeit).'
                    ]]
                ];
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

            return [
                'json_response' => ['success' => true, 'data' => [
                    'status' => $lessonInfo['status'],
                    'message' => $message,
                    'details' => $lessonInfo['data'] ?? null
                ]]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }
    
    /**
     * API: Holt die aktuelle Stunde des Lehrers UND die Schülerliste dafür.
     * (Unverändert)
     */
    public function getCurrentLessonWithStudentsApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
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
                return [
                    'json_response' => ['success' => true, 'data' => ['status' => 'Außerhalb der Zeit', 'lesson' => null, 'students' => []]]
                ];
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
                // KORREKTUR: Daten können ein Array sein (parallele Kurse)
                // Wir nehmen den ersten Eintrag für die Anwesenheit
                $lessonToUse = is_array($lessonData) ? $lessonData[0] : $lessonData;
                $classId = $lessonToUse['class_id'];
                
                $students = $this->userRepo->getStudentsByClassId($classId);
                $attendance = $this->attendanceRepo->getAttendance($classId, $currentDate, $currentPeriod);
                
                return [
                    'json_response' => ['success' => true, 'data' => [
                        'status' => $lessonInfo['status'],
                        'lesson' => $lessonToUse, // Nur den ersten Eintrag senden
                        'students' => $students,
                        'attendance' => $attendance,
                        'context' => ['date' => $currentDate, 'period' => $currentPeriod]
                    ]]
                ];

            } else {
                return [
                    'json_response' => ['success' => true, 'data' => ['status' => $lessonInfo['status'], 'lesson' => null, 'students' => []]]
                ];
            }

        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }
    
    /**
     * API: Speichert die Anwesenheitsliste.
     * (Unverändert)
     */
    public function saveAttendanceApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
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

            if (!$success) {
                throw new Exception("Anwesenheit konnte nicht gespeichert werden.", 500);
            }

            $logDetails = [
                'date' => $date, 
                'period' => $period, 
                'student_count' => count($students)
            ];

            return [
                'json_response' => ['success' => true, 'message' => 'Anwesenheit gespeichert!'],
                'log_action' => 'save_attendance',
                'log_target_type' => 'class',
                'log_target_id' => $classId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }
    
    /**
     * API: Holt die Voraussetzungen (Fächer und unterrichtete Klassen) für das Event-Formular.
     * (Unverändert)
     */
    public function getPrerequisitesApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];

            $subjects = $this->stammdatenRepo->getSubjects();
            $classes = $this->planRepo->getClassesForTeacher($teacherId);

            return [
                'json_response' => [
                    'success' => true,
                    'data' => [
                        'subjects' => $subjects,
                        'classes' => $classes
                    ]
                ]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    // --- SPRECHSTUNDEN (Refaktorisiert auf AppointmentService) ---

    /**
     * API: Holt die definierten Sprechstundenfenster des eingeloggten Lehrers.
     * MODIFIZIERT: Nutzt AppointmentService.
     */
    public function getOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) {
            
            $teacherUserId = $_SESSION['user_id'];
            // Logik ausgelagert:
            $availabilities = $this->appointmentService->getAvailabilities($teacherUserId);
            
            return [
                'json_response' => ['success' => true, 'data' => $availabilities]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    /**
     * API: Speichert ein neues Sprechstundenfenster für den eingeloggten Lehrer.
     * MODIFIZIERT: Nutzt AppointmentService.
     */
    public function saveOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $teacherUserId = $_SESSION['user_id'];
            
            // Logik und Validierung ausgelagert:
            // Service wirft Exception bei ungültigen Daten
            $newId = $this->appointmentService->createAvailability($teacherUserId, $data);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Sprechzeit erfolgreich gespeichert.', 'data' => ['availability_id' => $newId]],
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

    /**
     * API: Löscht ein Sprechstundenfenster des eingeloggten Lehrers.
     * MODIFIZIERT: Nutzt AppointmentService.
     */
    public function deleteOfficeHoursApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $teacherUserId = $_SESSION['user_id'];
            $availabilityId = filter_var($data['availability_id'] ?? null, FILTER_VALIDATE_INT);

            // Logik und Validierung ausgelagert:
            // Service wirft Exception bei 400 (fehlende ID) oder 404 (nicht gefunden)
            $this->appointmentService->deleteAvailability($teacherUserId, $availabilityId);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Sprechzeit erfolgreich gelöscht.'],
                'log_action' => 'delete_office_hours',
                'log_target_type' => 'teacher_availability',
                'log_target_id' => $availabilityId
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }

    
    /**
     * Hilfsfunktion: Wandelt 'Hi'-Zeitstempel in Periodennummer um.
     * (Unverändert)
     */
    private function getCurrentPeriod(int $hourMinute): ?int
    {
        // (Zeiten wie zuvor)
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
    
    /**
     * Hilfsfunktion: Formatiert Standort-Info.
     * (Unverändert)
     */
    private function formatLessonInfo(array $info): string
    {
        // KORREKTUR: Muss mit Array von $info['data'] umgehen (parallele Kurse)
        $data = $info['data'] ?? null;
        
        // Wenn $data ein Array ist (parallele Kurse), nimm den ersten Eintrag
        if (is_array($data) && isset($data[0])) {
             $data = $data[0];
             // Optional: Nachricht anpassen, um Mehrfachbelegung anzuzeigen
             // z.B.: $info['status'] = "Unterricht (parallel)";
        }
        
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