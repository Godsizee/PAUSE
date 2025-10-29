<?php
// app/Http/Controllers/Planer/AbsenceController.php

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

/**
 * NEU: Controller für die Verwaltung von Lehrer-Abwesenheiten (nur für Planer/Admins).
 */
class AbsenceController
{
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
     * Stellt Daten für das Formular bereit (Lehrerliste).
     */
    public function index()
    {
        Security::requireRole(['admin', 'planer']);
        global $config;
        $config = Database::getConfig();

        $page_title = 'Lehrer-Abwesenheiten';
        $body_class = 'planer-dashboard-body'; // Verwendet dasselbe Layout wie der Planer

        try {
            // Lade alle Lehrer für das Dropdown-Menü
            $availableTeachers = $this->stammdatenRepo->getTeachers();
            
            // Lade die erlaubten Abwesenheitstypen (aus dem Repository, falls sie in der DB wären, sonst hartcodiert)
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
     */
    public function getAbsencesApi()
    {
        Security::requireRole(['admin', 'planer']);
        header('Content-Type: application/json');

        try {
            $startDate = filter_input(INPUT_GET, 'start', FILTER_UNSAFE_RAW); // YYYY-MM-DD
            $endDate = filter_input(INPUT_GET, 'end', FILTER_UNSAFE_RAW); // YYYY-MM-DD

            if (!$startDate || !$endDate) {
                // Standard: Aktueller Monat
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $startDate = $today->format('Y-m-01');
                $endDate = $today->format('Y-m-t');
            }

            // Validierung
            if (DateTime::createFromFormat('Y-m-d', $startDate) === false || DateTime::createFromFormat('Y-m-d', $endDate) === false) {
                throw new Exception("Ungültiges Datumsformat.", 400);
            }

            // KORREKTUR: Diese Methode verwendet jetzt die Parameter
            $absences = $this->absenceRepo->getAbsencesForPeriod($startDate, $endDate);

            echo json_encode(['success' => true, 'data' => $absences]);

        } catch (Exception $e) {
            $code = $e->getCode() === 400 ? 400 : 500;
            http_response_code($code);
            error_log("API Error (getAbsencesApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Erstellt oder aktualisiert eine Abwesenheit.
     */
    public function saveAbsenceApi()
    {
        Security::requireRole(['admin', 'planer']);
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $data = json_decode(file_get_contents('php://input'), true);

            // KORREKTUR: absence_id für Updates/Erstellung
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

            // Hole die gültigen Typen
            $validTypes = $this->absenceRepo->getAbsenceTypes();
            if (!in_array($reason, $validTypes)) {
                throw new Exception("Ungültiger Abwesenheitsgrund.", 400);
            }

            // KORREKTUR: Übergibt die absenceId (kann null sein) an die Repository-Methode
            // Die Methode gibt den vollständigen, gespeicherten Datensatz zurück.
            $savedAbsence = $this->absenceRepo->createAbsence($absenceId, $teacherId, $startDate, $endDate, $reason, $comment);
            $newId = $savedAbsence['absence_id'];

            AuditLogger::log(
                $absenceId ? 'update_absence' : 'create_absence',
                'teacher_absence',
                $newId,
                [
                    'teacher_id' => $teacherId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $reason
                ]
            );

            // KORREKTUR: $newAbsence ist bereits $savedAbsence
            echo json_encode(['success' => true, 'message' => 'Abwesenheit gespeichert.', 'data' => $savedAbsence]);

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (saveAbsenceApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * API: Löscht eine Abwesenheit.
     */
    public function deleteAbsenceApi()
    {
        Security::requireRole(['admin', 'planer']);
        Security::verifyCsrfToken();
        header('Content-Type: application/json');

        try {
            $data = json_decode(file_get_contents('php://input'), true);
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

            if ($success) {
                AuditLogger::log(
                    'delete_absence',
                    'teacher_absence',
                    $absenceId,
                    [
                        'teacher_id' => $absence['teacher_id'],
                        'reason' => $absence['reason'],
                        'start_date' => $absence['start_date']
                    ]
                );
                echo json_encode(['success' => true, 'message' => 'Abwesenheit gelöscht.']);
            } else {
                throw new Exception("Abwesenheit konnte nicht gelöscht werden.", 500);
            }

        } catch (Exception $e) {
            $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($code);
            error_log("API Error (deleteAbsenceApi): " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}

