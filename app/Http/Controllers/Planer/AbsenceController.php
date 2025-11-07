<?php
// app/Http/Controllers/Planer/AbsenceController.php

// MODIFIZIERT:
// 1. ApiHandlerTrait importiert und verwendet.
// 2. getAbsencesApi (GET) nutzt handleApiRequest('inputType' => 'get').
// 3. saveAbsenceApi (JSON) nutzt handleApiRequest('inputType' => 'json').
// 4. deleteAbsenceApi (JSON) nutzt handleApiRequest('inputType' => 'json').
// 5. Alle manuellen try/catch, header(), json_decode(), json_encode() und Security-Checks
//    wurden aus den API-Methoden entfernt und in den Trait-Callback verschoben.

namespace App\Http\Controllers\Planer;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\TeacherAbsenceRepository;
use App\Repositories\StammdatenRepository;
use App\Services\AuditLogger; // Import bleibt (wird vom Trait genutzt)
use App\Http\Traits\ApiHandlerTrait; // NEU: Trait importieren
use Exception;
use PDO;
use DateTime;
use DateTimeZone;

/**
 * Controller für die Verwaltung von Lehrer-Abwesenheiten (nur für Planer/Admins).
 */
class AbsenceController
{
    // NEU: Trait für API-Behandlung einbinden
    use ApiHandlerTrait;

    private PDO $pdo;
    private TeacherAbsenceRepository $absenceRepo;
    private StammdatenRepository $stammdatenRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->absenceRepo = new TeacherAbsenceRepository($this->pdo);
        $this->stammdatenRepo = new StammdatenRepository($this->pdo);
    }

    /**
     * Zeigt die Hauptseite für die Abwesenheitsverwaltung an.
     * (Unverändert)
     */
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

    /**
     * API: Holt Abwesenheiten für einen bestimmten Zeitraum (z.B. einen Monat).
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist $_GET.
     */
    public function getAbsencesApi()
    {
        $this->handleApiRequest(function($data) { // $data ist $_GET
            
            $startDate = $data['start'] ?? null; // YYYY-MM-DD
            $endDate = $data['end'] ?? null; // YYYY-MM-DD

            if (!$startDate || !$endDate) {
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $startDate = $today->format('Y-m-01');
                $endDate = $today->format('Y-m-t');
            }

            if (DateTime::createFromFormat('Y-m-d', $startDate) === false || DateTime::createFromFormat('Y-m-d', $endDate) === false) {
                throw new Exception("Ungültiges Datumsformat.", 400);
            }

            $absences = $this->absenceRepo->getAbsencesForPeriod($startDate, $endDate);

            // Rückgabe für den Trait (Erfolgsfall)
            return [
                'json_response' => ['success' => true, 'data' => $absences]
            ];

        }, [
            'inputType' => 'get',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    /**
     * API: Erstellt oder aktualisiert eine Abwesenheit.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function saveAbsenceApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
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

            // Log-Details vorbereiten
            $logDetails = [
                'teacher_id' => $teacherId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $reason
            ];

            // Rückgabe für den Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Abwesenheit gespeichert.', 'data' => $savedAbsence],
                'log_action' => $absenceId ? 'update_absence' : 'create_absence',
                'log_target_type' => 'teacher_absence',
                'log_target_id' => $newId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }

    /**
     * API: Löscht eine Abwesenheit.
     * MODIFIZIERT: Nutzt ApiHandlerTrait. $data ist geparstes JSON.
     */
    public function deleteAbsenceApi()
    {
        $this->handleApiRequest(function($data) { // $data ist JSON-Body
            
            $absenceId = filter_var($data['absence_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$absenceId) {
                throw new Exception("Fehlende Abwesenheits-ID.", 400);
            }

            // Hole Daten für das Log, bevor gelöscht wird
            $absence = $this->absenceRepo->getAbsenceById($absenceId);
            if (!$absence) {
                throw new Exception("Abwesenheit nicht gefunden.", 404);
            }

            $success = $this->absenceRepo->deleteAbsence($absenceId);
            if (!$success) {
                // Sollte nicht passieren, wenn getAbsenceById funktioniert hat, aber zur Sicherheit
                throw new Exception("Abwesenheit konnte nicht gelöscht werden.", 500);
            }

            // Log-Details vorbereiten
            $logDetails = [
                'teacher_id' => $absence['teacher_id'],
                'reason' => $absence['reason'],
                'start_date' => $absence['start_date']
            ];

            // Rückgabe für den Trait
            return [
                'json_response' => ['success' => true, 'message' => 'Abwesenheit gelöscht.'],
                'log_action' => 'delete_absence',
                'log_target_type' => 'teacher_absence',
                'log_target_id' => $absenceId,
                'log_details' => $logDetails
            ];

        }, [
            'inputType' => 'json',
            'checkRole' => ['admin', 'planer']
        ]);
    }
}