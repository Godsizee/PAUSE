<?php
// app/Http/Controllers/DashboardController.php

// MODIFIZIZERT:
// 1. AppointmentService importiert und im Konstruktor injiziert.
// 2. AppointmentRepository entfernt (wird jetzt vom Service verwaltet).
// 3. Methoden getAvailableSlotsApi, bookAppointmentApi, cancelAppointmentApi
//    refaktorisiert, um den AppointmentService zu nutzen.
// 4. Geschäftslogik (z.B. Teacher-ID-Lookup, Datumsvalidierung) aus den
//    Callbacks entfernt und in den Service verschoben.

namespace App\Http\Controllers;

use App\Core\Security;
use App\Core\Utils;
use App\Core\Database;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\StudentNoteRepository;
use App\Services\AppointmentService; 
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

class DashboardController
{
    use ApiHandlerTrait;

    private PDO $pdo;
    private UserRepository $userRepository;
    private PlanRepository $planRepository;
    // VERALTET: private AppointmentRepository $appointmentRepo;
    private StudentNoteRepository $noteRepo;
    private AppointmentService $appointmentService; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->planRepository = new PlanRepository($this->pdo);
        $this->noteRepo = new StudentNoteRepository($this->pdo);
        
        // NEU: AppointmentService instanziieren (benötigt Repos)
        $this->appointmentService = new AppointmentService(
            new \App\Repositories\AppointmentRepository($this->pdo), // Repository hier übergeben
            $this->userRepository
        );
        // VERALTET: $this->appointmentRepo = new AppointmentRepository($this->pdo);
    }

    /**
     * Zeigt das Haupt-Dashboard an (oder leitet Admin/Planer weiter).
     * (Unverändert)
     */
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


    /**
     * API: Laden des kompletten Wochenplans, Vertretungen, Termine & Notizen.
     * MODIFIZIERT: Holt Termine jetzt über den AppointmentService.
     */
    public function getWeeklyData()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
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
                throw new Exception("Benutzer nicht gefunden.", 404);
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
                    // MODIFIZIERT: Nutzt Service
                    $appointments = $this->appointmentService->appointmentRepo->getAppointmentsForStudent($userId, $startDate, $endDate);
                    $notes = $this->noteRepo->getNotesForWeek($userId, $year, $calendarWeek);
                }
            } elseif ($userRole === 'lehrer' && !empty($user['teacher_id'])) {
                 $targetGroup = 'teacher';
                 if ($this->planRepository->isWeekPublishedFor($targetGroup, $year, $calendarWeek)) {
                      $regularTimetable = $this->planRepository->getPublishedTimetableForTeacher($user['teacher_id'], $year, $calendarWeek);
                      $substitutions = $this->planRepository->getPublishedSubstitutionsForTeacherWeek($user['teacher_id'], $year, $calendarWeek);
                      // MODIFIZIERT: Nutzt Service
                      $appointments = $this->appointmentService->appointmentRepo->getAppointmentsForTeacher($userId, $startDate, $endDate);
                 }
            }

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => [
                    'timetable' => $regularTimetable,
                    'substitutions' => $substitutions,
                    'appointments' => $appointments,
                    'notes' => $notes
                ]]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['schueler', 'lehrer']
        ]);
    }

    /**
     * API: Speichert eine private Notiz für einen Schüler.
     * (Unverändert)
     */
    public function saveNoteApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
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

            if (!$success) {
                throw new Exception("Notiz konnte nicht gespeichert werden.", 500);
            }

            $logDetails = [
                'year' => $year,
                'week' => $calendarWeek,
                'day' => $dayOfWeek,
                'period' => $periodNumber,
                'action' => empty(trim($content)) ? 'deleted' : 'saved'
            ];

            return [
                'json_response' => ['success' => true, 'message' => 'Notiz gespeichert.'],
                'log_action' => 'save_student_note',
                'log_target_type' => 'student_note',
                'log_target_id' => $userId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'schueler'
        ]);
    }

    /**
     * API: Holt die verfügbaren Slots für einen Lehrer an einem Datum.
     * MODIFIZIERT: Nutzt AppointmentService. $data ist $_GET.
     */
    public function getAvailableSlotsApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $teacherStammdatenId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $date = filter_var($data['date'] ?? null, FILTER_UNSAFE_RAW);

            // Logik und Validierung ausgelagert:
            // Service wirft Exceptions bei 400, 404
            $slots = $this->appointmentService->getAvailableSlots($teacherStammdatenId, $date);
            
            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $slots]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'schueler'
        ]);
    }

    /**
     * API: Bucht einen Termin.
     * MODIFIZIERT: Nutzt AppointmentService. $data ist geparstes JSON.
     */
    public function bookAppointmentApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $studentUserId = $_SESSION['user_id'];

            // Logik und Validierung ausgelagert:
            // Service wirft Exceptions bei 400, 404, 409
            $newId = $this->appointmentService->bookAppointment($studentUserId, $data);
            
            // Log-Details
            $logDetails = [
                'teacher_user_id' => $data['teacher_id'], // Speichert die Stammdaten-ID zur Referenz
                'date' => $data['date'],
                'time' => $data['time']
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Sprechstunde erfolgreich gebucht!'],
                'log_action' => 'book_appointment',
                'log_target_type' => 'appointment',
                'log_target_id' => $newId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'schueler'
        ]);
    }

    /**
     * API: Storniert einen Termin.
     * MODIFIZIERT: Nutzt AppointmentService. $data ist geparstes JSON.
     */
    public function cancelAppointmentApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $role = $_SESSION['user_role'];
            $appointmentId = filter_var($data['appointment_id'] ?? null, FILTER_VALIDATE_INT);

            // Logik und Validierung ausgelagert:
            // Service wirft Exceptions bei 400, 403, 404
            $this->appointmentService->cancelAppointment($appointmentId, $userId, $role);
            
            // Log-Details
            $logDetails = ['cancelled_by_role' => $role];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Termin erfolgreich storniert.'],
                'log_action' => 'cancel_appointment',
                'log_target_type' => 'appointment',
                'log_target_id' => $appointmentId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['schueler', 'lehrer'] // Beide Rollen dürfen stornieren
        ]);
    }
}