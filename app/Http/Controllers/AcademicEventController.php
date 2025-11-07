<?php
// app/Http/Controllers/AcademicEventController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. getForStudent (GET) nutzt handleApiRequest('inputType' => 'get').
// 3. getForTeacher (GET) nutzt handleApiRequest('inputType' => 'get').
// 4. createOrUpdate (JSON) nutzt handleApiRequest('inputType' => 'json').
// 5. delete (JSON) nutzt handleApiRequest('inputType' => 'json').
// 6. Alle manuellen try/catch, header(), json_decode(), json_encode() und Security-Checks
//    wurden aus den API-Methoden entfernt und in den Trait-Callback verschoben.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AcademicEventRepository;
use App\Repositories\UserRepository;
use App\Repositories\StammdatenRepository;
use App\Services\AuditLogger; // Import bleibt (wird vom Trait genutzt)
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

class AcademicEventController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

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

    /**
     * API: Holt Events für den eingeloggten Schüler für eine bestimmte Woche.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getForStudent()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
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
                 $year = (int)$today->format('o'); // ISO year
                 $week = (int)$today->format('W'); // ISO week
            }

            $events = $this->eventRepo->getEventsForClassByWeek($classId, $year, $week);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $events]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'schueler'
        ]);
    }

    /**
     * API: Holt Events, die vom eingeloggten Lehrer erstellt wurden.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getForTeacher()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $userId = $_SESSION['user_id'];
            $daysInFuture = 14; // Standardmäßig die nächsten 14 Tage

            $events = $this->eventRepo->getEventsByTeacher($userId, $daysInFuture);

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'data' => $events]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => 'lehrer'
        ]);
    }

    /**
     * API: Erstellt oder aktualisiert ein Event (Aufgabe/Klausur/Info).
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function createOrUpdate()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $user = $this->userRepo->findById($userId);
            if (!$user || !$user['teacher_id']) {
                throw new Exception("Lehrerprofil nicht gefunden.", 403);
            }
            $teacherId = $user['teacher_id']; // teacher_id aus teachers Tabelle

            // Validierung der Eingaben
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

            // Berechtigungsprüfung
            if (!$this->eventRepo->checkTeacherAuthorization($teacherId, $classId, $dueDate)) {
                 error_log("Hinweis: Lehrer {$userId} erstellt Event für Klasse {$classId} an Datum {$dueDate} ohne expliziten Unterrichtsnachweis.");
            }

            // Speichern
            $savedEvent = $this->eventRepo->saveEvent(
                $eventId,
                $userId, // Wichtig: Die user_id des Lehrers
                $classId,
                $subjectId,
                $eventType,
                $title,
                $dueDate,
                $description
            );

            // Log-Details
            $logDetails = [
                'type' => $eventType,
                'title' => $title,
                'class_id' => $classId,
                'due_date' => $dueDate
            ];
            
            // Rückgabe für Trait
            return [
                'json_response' => [
                    'success' => true,
                    'message' => 'Eintrag erfolgreich ' . ($eventId ? 'aktualisiert' : 'erstellt') . '.',
                    'data' => $savedEvent
                ],
                'log_action' => $eventId ? 'update_event' : 'create_event',
                'log_target_type' => 'academic_event',
                'log_target_id' => $savedEvent['event_id'],
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }

    /**
     * API: Löscht ein Event.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function delete()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $userId = $_SESSION['user_id'];
            $eventId = filter_var($data['event_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$eventId) {
                throw new Exception("Keine Event-ID angegeben.", 400);
            }

            // Hole Event-Details vor dem Löschen für das Logging
            $eventToDelete = $this->eventRepo->getEventById($eventId);
            if (!$eventToDelete) {
                // Wenn das Event nicht existiert, RowCount = 0, also behandeln wir es wie einen Fehler
                throw new Exception("Eintrag nicht gefunden.", 404);
            }
            
            // Berechtigungsprüfung (wird auch im Repository geprüft, aber hier für Log-Details)
            if ($eventToDelete['user_id'] != $userId) {
                 throw new Exception("Sie sind nicht berechtigt, diesen Eintrag zu löschen.", 403);
            }

            $success = $this->eventRepo->deleteEvent($eventId, $userId);
            if (!$success) {
                // Sollte durch die Prüfungen oben nicht passieren
                throw new Exception("Eintrag konnte nicht gelöscht werden.", 500);
            }

            // Log-Details
            $logDetails = [
                 'title' => $eventToDelete['title'] ?? 'N/A',
                 'type' => $eventToDelete['event_type'] ?? 'N/A',
                 'class_id' => $eventToDelete['class_id'] ?? 'N/A',
                 'due_date' => $eventToDelete['due_date'] ?? 'N/A'
            ];

            // Rückgabe für Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Eintrag erfolgreich gelöscht.'],
                'log_action' => 'delete_event',
                'log_target_type' => 'academic_event',
                'log_target_id' => $eventId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => 'lehrer'
        ]);
    }
}