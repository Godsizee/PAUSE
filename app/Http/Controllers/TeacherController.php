<?php
// app/Http/Controllers/TeacherController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\StammdatenRepository;
use App\Repositories\PlanRepository;
use App\Repositories\UserRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\AppointmentRepository; // NEU
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

class TeacherController
{
    private PDO $pdo;
    private StammdatenRepository $stammdatenRepo;
    private PlanRepository $planRepo;
    private UserRepository $userRepo;
    private AttendanceRepository $attendanceRepo;
    private AppointmentRepository $appointmentRepo; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->attendanceRepo = new AttendanceRepository($this->pdo);
        $this->appointmentRepo = new AppointmentRepository($this->pdo); // NEU
    }

    /**
     * API: Sucht nach Lehrern basierend auf einer Suchanfrage.
     * KORREKTUR: Jetzt für 'lehrer' UND 'schueler' verfügbar.
     */
    public function searchColleaguesApi()
    {
        Security::requireRole(['lehrer', 'schueler']); // ERWEITERT
        header('Content-Type: application/json');

        try {
            $query = filter_input(INPUT_GET, 'query', FILTER_UNSAFE_RAW) ?? '';
            
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

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Findet den aktuellen Aufenthaltsort (Stunde/Raum) eines Lehrers.
     * Nur für eingeloggte Lehrer.
     */
    public function findColleagueApi()
    {
        Security::requireRole('lehrer');
        header('Content-Type: application/json');

        try {
            $teacherId = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
            if (!$teacherId) {
                throw new Exception("Keine Lehrer-ID angegeben.", 400);
            }

            // 1. Aktuelle Zeit, Woche, Tag und Stunde ermitteln
            $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
            $currentDate = $now->format('Y-m-d');
            $currentYear = (int)$now->format('o');
            $currentWeek = (int)$now->format('W');
            $currentDayOfWeek = (int)$now->format('N'); // 1 (Mo) - 7 (So)
            
            $currentHourMinute = (int)$now->format('Hi');
            $currentPeriod = $this->getCurrentPeriod($currentHourMinute);

            if ($currentDayOfWeek > 5 || $currentPeriod === null) {
                echo json_encode(['success' => true, 'data' => [
                    'status' => 'Außerhalb der Zeit',
                    'message' => 'Der Kollege befindet sich wahrscheinlich nicht im Unterricht (Wochenende oder außerhalb der Unterrichtszeit).'
                ]]);
                exit();
            }

            // 2. Repository abfragen
            $lessonInfo = $this->planRepo->findTeacherLocation(
                $teacherId,
                $currentDate,
                $currentYear,
                $currentWeek,
                $currentDayOfWeek,
                $currentPeriod
            );
            
            // 3. Antwort basierend auf dem Ergebnis formatieren
            $message = $this->formatLessonInfo($lessonInfo);

            echo json_encode(['success' => true, 'data' => [
                'status' => $lessonInfo['status'],
                'message' => $message,
                'details' => $lessonInfo['data'] ?? null
            ]]);

        } catch (Exception $e) {
            http_response_code($e->getCode() === 400 ? 400 : 500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    /**
     * API: Holt die aktuelle Stunde des Lehrers UND die Schülerliste dafür.
     */
    public function getCurrentLessonWithStudentsApi()
    {
        Security::requireRole('lehrer');
        header('Content-Type: application/json');

        try {
            // 1. Aktuellen Lehrer-Benutzer holen
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];

            // 2. Aktuelle Zeit/Periode ermitteln
            $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
            $currentDate = $now->format('Y-m-d');
            $currentYear = (int)$now->format('o');
            $currentWeek = (int)$now->format('W');
            $currentDayOfWeek = (int)$now->format('N');
            $currentHourMinute = (int)$now->format('Hi');
            $currentPeriod = $this->getCurrentPeriod($currentHourMinute);
            
            if ($currentDayOfWeek > 5 || $currentPeriod === null) {
                echo json_encode(['success' => true, 'data' => ['status' => 'Außerhalb der Zeit', 'lesson' => null, 'students' => []]]);
                exit();
            }
            
            // 3. Aktuellen Standort/Unterricht finden
            $lessonInfo = $this->planRepo->findTeacherLocation(
                $teacherId,
                $currentDate,
                $currentYear,
                $currentWeek,
                $currentDayOfWeek,
                $currentPeriod
            );
            
            // 4. Prüfen, ob der Lehrer unterrichtet
            if ($lessonInfo['status'] === 'Unterricht' || $lessonInfo['status'] === 'Vertretung') {
                $lessonData = $lessonInfo['data'];
                $classId = $lessonData['class_id'];
                
                // 5. Schülerliste holen
                $students = $this->userRepo->getStudentsByClassId($classId);
                
                // 6. Bereits erfasste Anwesenheit holen
                $attendance = $this->attendanceRepo->getAttendance($classId, $currentDate, $currentPeriod);
                
                echo json_encode(['success' => true, 'data' => [
                    'status' => $lessonInfo['status'],
                    'lesson' => $lessonData,
                    'students' => $students,
                    'attendance' => $attendance,
                    'context' => ['date' => $currentDate, 'period' => $currentPeriod] // Kontext für Speichern
                ]]);

            } else {
                // Freistunde, Entfall etc.
                echo json_encode(['success' => true, 'data' => ['status' => $lessonInfo['status'], 'lesson' => null, 'students' => []]]);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    /**
     * API: Speichert die Anwesenheitsliste.
     */
    public function saveAttendanceApi()
    {
        Security::requireRole('lehrer');
        Security::verifyCsrfToken();
        header('Content-Type: application/json');
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Ungültige Daten (JSON) empfangen.", 400);
            }
            
            // 1. Aktuellen Lehrer-Benutzer holen
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherUserId = $user['user_id']; // ID aus der users-Tabelle, nicht teachers
            
            // 2. Daten validieren
            $classId = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            $date = $data['date'] ?? null;
            $period = filter_var($data['period_number'] ?? null, FILTER_VALIDATE_INT);
            $students = $data['students'] ?? [];

            if (!$classId || !$date || !$period || empty($students) || !is_array($students)) {
                throw new Exception("Fehlende oder ungültige Daten (Klasse, Datum, Stunde oder Schülerliste).", 400);
            }
            
            // 3. Speichern
            $success = $this->attendanceRepo->saveAttendance(
                $teacherUserId,
                $classId,
                $date,
                $period,
                $students
            );

            if ($success) {
                AuditLogger::log('save_attendance', 'class', $classId, [
                    'date' => $date, 
                    'period' => $period, 
                    'student_count' => count($students)
                ]);
                echo json_encode(['success' => true, 'message' => 'Anwesenheit gespeichert!']);
            } else {
                throw new Exception("Anwesenheit konnte nicht gespeichert werden.");
            }

        } catch (Exception $e) {
            $code = $e->getCode() === 400 ? 400 : 500;
            http_response_code($code);
            error_log("Save Attendance Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    
    /**
     * API: Holt die Voraussetzungen (Fächer und unterrichtete Klassen) für das Event-Formular.
     */
    public function getPrerequisitesApi()
    {
        Security::requireRole('lehrer');
        header('Content-Type: application/json');

        try {
            // 1. Aktuellen Lehrer-Benutzer holen
            $user = $this->userRepo->findById($_SESSION['user_id']);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Kein gültiges Lehrerprofil gefunden.", 403);
            }
            $teacherId = $user['teacher_id'];

            // 2. Alle Fächer holen
            $subjects = $this->stammdatenRepo->getSubjects();
            
            // 3. Nur die Klassen holen, die dieser Lehrer unterrichtet
            $classes = $this->planRepo->getClassesForTeacher($teacherId);

            echo json_encode([
                'success' => true,
                'data' => [
                    'subjects' => $subjects,
                    'classes' => $classes
                ]
            ]);

        } catch (Exception $e) {
            $code = $e->getCode() === 403 ? 403 : 500;
            http_response_code($code);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }


    // --- NEUE METHODEN FÜR SPRECHSTUNDEN ---

    /**
     * API: Holt die definierten Sprechstundenfenster des eingeloggten Lehrers.
     */
    public function getOfficeHoursApi()
    {
        Security::requireRole('lehrer');
        header('Content-Type: application/json');
        try {
            $teacherUserId = $_SESSION['user_id'];
            $availabilities = $this->appointmentRepo->getAvailabilities($teacherUserId);
            echo json_encode(['success' => true, 'data' => $availabilities]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Sprechzeiten: ' . $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Speichert ein neues Sprechstundenfenster für den eingeloggten Lehrer.
     */
    public function saveOfficeHoursApi()
    {
        Security::requireRole('lehrer');
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $teacherUserId = $_SESSION['user_id'];
            $data = json_decode(file_get_contents('php://input'), true);

            // Validierung
            $dayOfWeek = filter_var($data['day_of_week'] ?? null, FILTER_VALIDATE_INT);
            $startTime = $data['start_time'] ?? null; // z.B. 14:00
            $endTime = $data['end_time'] ?? null;
            $slotDuration = filter_var($data['slot_duration'] ?? 15, FILTER_VALIDATE_INT);

            if (!$dayOfWeek || !$startTime || !$endTime || !$slotDuration || $dayOfWeek < 1 || $dayOfWeek > 5 || $slotDuration < 5) {
                throw new Exception("Ungültige Eingabedaten.", 400);
            }

            $newId = $this->appointmentRepo->createAvailability($teacherUserId, $dayOfWeek, $startTime, $endTime, $slotDuration);
            
            AuditLogger::log('create_office_hours', 'teacher_availability', $newId, $data);
            
            echo json_encode(['success' => true, 'message' => 'Sprechzeit erfolgreich gespeichert.', 'data' => ['availability_id' => $newId]]);

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern: ' . $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Löscht ein Sprechstundenfenster des eingeloggten Lehrers.
     */
    public function deleteOfficeHoursApi()
    {
        Security::requireRole('lehrer');
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $teacherUserId = $_SESSION['user_id'];
            $data = json_decode(file_get_contents('php://input'), true);
            $availabilityId = filter_var($data['availability_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$availabilityId) {
                throw new Exception("Keine ID angegeben.", 400);
            }

            $success = $this->appointmentRepo->deleteAvailability($availabilityId, $teacherUserId);
            
            if ($success) {
                AuditLogger::log('delete_office_hours', 'teacher_availability', $availabilityId);
                echo json_encode(['success' => true, 'message' => 'Sprechzeit erfolgreich gelöscht.']);
            } else {
                throw new Exception("Sprechzeit nicht gefunden oder keine Berechtigung.", 404);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Löschen: ' . $e->getMessage()]);
        }
        exit();
    }

    
    /**
     * Hilfsfunktion: Wandelt einen 'Hi'-Zeitstempel in eine Periodennummer um.
     */
    private function getCurrentPeriod(int $hourMinute): ?int
    {
        if ($hourMinute >= 800 && $hourMinute <= 845) return 1;
        if ($hourMinute >= 855 && $hourMinute <= 940) return 2;
        if ($hourMinute >= 940 && $hourMinute <= 1025) return 3;
        if ($hourMinute >= 1035 && $hourMinute <= 1120) return 4;
        if ($hourMinute >= 1120 && $hourMinute <= 1205) return 5;
        // Mittagspause
        if ($hourMinute >= 1305 && $hourMinute <= 1350) return 6;
        if ($hourMinute >= 1350 && $hourMinute <= 1435) return 7;
        if ($hourMinute >= 1445 && $hourMinute <= 1530) return 8;
        if ($hourMinute >= 1530 && $hourMinute <= 1615) return 9;
        if ($hourMinute >= 1625 && $hourMinute <= 1710) return 10;
        
        return null; // Außerhalb der Zeit
    }
    
    /**
     * Hilfsfunktion: Formatiert die Rohdaten aus dem Repository in eine lesbare Nachricht.
     */
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