<?php
// app/Http/Controllers/AcademicEventController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AcademicEventRepository;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository; // NEU: Für Fächer etc. im Formular
use App\Services\AuditLogger;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

class AcademicEventController
{
    private PDO $pdo;
    private AcademicEventRepository $eventRepo;
    private UserRepository $userRepo;
    private StammdatenRepository $stammdatenRepo; // NEU

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->eventRepo = new AcademicEventRepository($this->pdo);
        $this->userRepo = new UserRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo); // NEU
    }

    /**
     * API: Holt Events für den eingeloggten Schüler für eine bestimmte Woche.
     */
    public function getForStudent()
    {
        Security::requireRole('schueler');
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $user = $this->userRepo->findById($userId);
            if (!$user || !$user['class_id']) {
                throw new Exception("Schülerdaten unvollständig (Klasse fehlt).", 400);
            }
            $classId = $user['class_id'];

            $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
            $week = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);

            if (!$year || !$week) {
                 $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                 $year = (int)$today->format('o'); // ISO year
                 $week = (int)$today->format('W'); // ISO week
            }

            $events = $this->eventRepo->getEventsForClassByWeek($classId, $year, $week);

            echo json_encode(['success' => true, 'data' => $events]);

        } catch (Exception $e) {
            $code = $e->getCode() === 400 ? 400 : 500;
            http_response_code($code);
            error_log("API Error (getForStudent): " . $e->getMessage()); // Log detailed error
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden der Termine: ' . $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Holt Events, die vom eingeloggten Lehrer erstellt wurden (für die nächsten 14 Tage).
     */
    public function getForTeacher()
    {
        Security::requireRole('lehrer');
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            // Kein zusätzlicher User-Check nötig, da requireRole prüft
            $daysInFuture = 14; // Standardmäßig die nächsten 14 Tage

            $events = $this->eventRepo->getEventsByTeacher($userId, $daysInFuture);

            echo json_encode(['success' => true, 'data' => $events]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("API Error (getForTeacher): " . $e->getMessage()); // Log detailed error
            echo json_encode(['success' => false, 'message' => 'Fehler beim Laden Ihrer Einträge: ' . $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Erstellt oder aktualisiert ein Event (Aufgabe/Klausur/Info).
     * Nur für Lehrer.
     */
    public function createOrUpdate()
    {
        Security::requireRole('lehrer');
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];
            $user = $this->userRepo->findById($userId); // Hole Lehrerdaten für teacher_id
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Lehrerprofil nicht gefunden.", 403);
            }
            $teacherId = $user['teacher_id']; // teacher_id aus teachers Tabelle

            $data = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Ungültige Daten (JSON) empfangen.", 400);
            }

            // Validierung der Eingaben
            $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT) ?: null; // Optional für Update
            $classId = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            $subjectId = filter_var($data['subject_id'] ?? null, FILTER_VALIDATE_INT) ?: null; // Optional
            $eventType = $data['event_type'] ?? null;
            $title = trim($data['title'] ?? '');
            $dueDate = $data['due_date'] ?? null;
            // KORREKTUR: period_number entfernt
            // $period = filter_var($data['period_number'] ?? null, FILTER_VALIDATE_INT) ?: null; // Optional
            $description = isset($data['description']) ? trim($data['description']) : null;

            if (!$classId || !$eventType || empty($title) || !$dueDate || !in_array($eventType, ['aufgabe', 'klausur', 'info'])) {
                throw new Exception("Fehlende oder ungültige Pflichtfelder (Typ, Klasse, Titel, Datum).", 400);
            }
            if (DateTime::createFromFormat('Y-m-d', $dueDate) === false) {
                 throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.", 400);
            }

            // Berechtigungsprüfung: Darf der Lehrer für diese Klasse/Datum erstellen?
            if (!$this->eventRepo->checkTeacherAuthorization($teacherId, $classId, $dueDate)) {
                 error_log("Hinweis: Lehrer {$userId} erstellt Event für Klasse {$classId} an Datum {$dueDate} ohne expliziten Unterrichtsnachweis.");
            }

            // KORREKTUR: Parameter $period entfernt
            $savedEvent = $this->eventRepo->saveEvent(
                $eventId,
                $userId, // Wichtig: Die user_id des Lehrers, nicht die teacher_id
                $classId,
                $subjectId,
                $eventType,
                $title,
                $dueDate,
                $description
            );

            // Logging
            $action = $eventId ? 'update_event' : 'create_event';
            AuditLogger::log($action, 'academic_event', $savedEvent['event_id'], [
                'type' => $eventType,
                'title' => $title,
                'class_id' => $classId,
                'due_date' => $dueDate
            ]);


            echo json_encode([
                'success' => true,
                'message' => 'Eintrag erfolgreich ' . ($eventId ? 'aktualisiert' : 'erstellt') . '.',
                'data' => $savedEvent // Gibt das gespeicherte Event zurück
            ]);

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (createOrUpdate Event): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Löscht ein Event.
     * Nur für den erstellenden Lehrer.
     */
    public function delete()
    {
        Security::requireRole('lehrer');
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['user_id'];

            $data = json_decode(file_get_contents('php://input'), true);
            $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$eventId) {
                throw new Exception("Keine Event-ID angegeben.", 400);
            }

            // Hole Event-Details vor dem Löschen für das Logging
            $eventToDelete = $this->eventRepo->getEventById($eventId);

            $success = $this->eventRepo->deleteEvent($eventId, $userId);

            if ($success) {
                AuditLogger::log('delete_event', 'academic_event', $eventId, [
                     'title' => $eventToDelete['title'] ?? 'N/A',
                     'type' => $eventToDelete['event_type'] ?? 'N/A',
                     'class_id' => $eventToDelete['class_id'] ?? 'N/A',
                     'due_date' => $eventToDelete['due_date'] ?? 'N/A'
                 ]);
                echo json_encode(['success' => true, 'message' => 'Eintrag erfolgreich gelöscht.']);
            } else {
                throw new Exception("Eintrag konnte nicht gelöscht werden (nicht gefunden oder keine Berechtigung).", 404);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
             // Spezifische Behandlung für 403 oder 404 aus dem Repository
            if (str_contains($e->getMessage(), 'Berechtigung') || str_contains($e->getMessage(), 'Authorization')) {
                $code = 403;
            } elseif (str_contains($e->getMessage(), 'gefunden') || str_contains($e->getMessage(), 'found')) {
                $code = 404;
            }
            http_response_code($code);
            error_log("API Error (delete Event): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}