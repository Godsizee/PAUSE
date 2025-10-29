<?php
// app/Http/Controllers/Planer/PlanController.php
namespace App\Http\Controllers\Planer;

use App\Core\Security;
use App\Core\Database;
use App\Repositories\PlanRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\TeacherAbsenceRepository; // NEU: Import
use Exception;
use PDO;
use DateTime;
use DateTimeZone; // Added explicit use
use App\Services\AuditLogger; // NEU: AuditLogger importieren

class PlanController
{
    private PDO $pdo;
    private PlanRepository $planRepository;
    private StammdatenRepository $stammdatenRepository; // <-- Deklaration
    private TeacherAbsenceRepository $absenceRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->planRepository = new PlanRepository($this->pdo);
        $this->stammdatenRepository = new StammdatenRepository($this->pdo); // <-- Initialisierung
        $this->absenceRepo = new TeacherAbsenceRepository($this->pdo);
    }

    public function index()
    {
        Security::requireRole(['planer', 'admin']);
        global $config;
        $config = Database::getConfig();
        $page_title = 'Stundenplan-Verwaltung';
        $body_class = 'planer-dashboard-body';
        // Ensure CSRF token is generated for forms/API calls on this page
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/planer/dashboard.php';
    }

     // --- API Helper ---
     /**
     * Helper to wrap common API request logic (auth, CSRF, response type, error handling)
     * @param callable $callback The actual logic to execute (sollte ein Array zurückgeben)
     * @param string $actionName Der Name der Aktion für das Audit-Log (z.B. 'create_entry')
     * @param string $targetType Der Typ des Ziels (z.B. 'timetable_entry')
     * @param bool $isGetRequest Ob es sich um eine GET-Anfrage handelt (Callback macht eigenen Echo)
     */
    private function handleApiRequest(callable $callback, string $actionName = '', string $targetType = '', bool $isGetRequest = false): void
    {
        header('Content-Type: application/json'); // Set header early
        try {
            if (!$isGetRequest) {
                Security::verifyCsrfToken(); // Verify CSRF token for non-GET requests
            }
            
            // Callback ausführen
            $result = $callback(); 

            if ($isGetRequest) {
                // GET request: Callback kümmert sich selbst um den Echo (wie bei getTimetableData)
                // $result ist in diesem Fall void
            } else {
                // POST/Modifying request: Callback hat Daten zurückgegeben
                // Aktion protokollieren
                if ($actionName) {
                    AuditLogger::log(
                        $actionName,
                        $targetType,
                        $result['log_target_id'] ?? null, // Spezifischer Schlüssel für die ID
                        $result['log_details'] ?? null    // Spezifischer Schlüssel für Details
                    );
                }

                // JSON-Antwort aus dem Ergebnis senden
                echo json_encode($result['json_response'], JSON_THROW_ON_ERROR);
            }

        } catch (Exception $e) {
            // Determine appropriate status code
            // *** UPDATED: Handle conflict exception (409) specifically ***
            $statusCode = 400; // Default Bad Request
            if (str_contains($e->getMessage(), 'CSRF')) {
                $statusCode = 403; // Forbidden
            } elseif (str_contains($e->getMessage(), 'KONFLIKT') || str_contains($e->getMessage(), 'Konflikt')) {
                $statusCode = 409; // Conflict
            } elseif (str_contains($e->getMessage(), 'existiert bereits')) { // Spezifischer Fehler für Vorlagenname
                $statusCode = 409; // Conflict
            }
            
            http_response_code($statusCode);
            
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit();
    }


    /**
     * API: Holt Stundenplan-Daten für den Planer (Klassen ODER Lehrer).
     * Inklusive Stammdaten und Veröffentlichungsstatus. (GET request - no CSRF needed)
     */
    public function getTimetableData()
    {
        Security::requireRole(['planer', 'admin']);
        // Definiere Callback innerhalb der Methode, um auf $this zuzugreifen
        $callback = function() {
             $classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
             $teacherId = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
             $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
             $calendarWeek = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);
             $date = $_GET['date'] ?? null; // For daily substitution display in planner

             if ($date && (DateTime::createFromFormat('Y-m-d', $date) === false)) {
                 throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.");
             }

             // Fetch base data (classes, teachers, etc.) only if needed or if no specific plan is requested
             $baseData = [];
             $absencesData = []; // NEU: Abwesenheiten initialisieren
             
             // KORREKTUR: Lade Stammdaten + Abwesenheiten (für Initial-Load)
             if (!$classId && !$teacherId && !$year && !$calendarWeek) { 
                 // KORREKTUR: Verwende $this->stammdatenRepository statt $this->stammdatenRepo
                 $baseData = [
                     'classes' => $this->stammdatenRepository->getClasses(),
                     'teachers' => $this->stammdatenRepository->getTeachers(),
                     'subjects' => $this->stammdatenRepository->getSubjects(),
                     'rooms' => $this->stammdatenRepository->getRooms(),
                     'templates' => $this->planRepository->getTemplates(), 
                 ];
                 // Lade Abwesenheiten für die nächsten Monate (z.B.)
                 $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                 $startDate = $today->format('Y-m-01');
                 $endDate = $today->modify('+3 months')->format('Y-m-t');
                 $absencesData = $this->absenceRepo->getAbsencesForDateRange($startDate, $endDate);
                 $baseData['absences'] = $absencesData; // Füge Abwesenheiten zu Stammdaten hinzu
             }

             $timetable = [];
             $substitutions = [];
             $publishStatus = ['student' => false, 'teacher' => false]; // Default

             // If a specific entity (class or teacher) is selected and year/week are provided
             if (($classId || $teacherId) && $year && $calendarWeek) {
                 $publishStatus = $this->planRepository->getPublishStatus($year, $calendarWeek);

                 if ($classId) {
                     $timetable = $this->planRepository->getTimetableForClassAsPlaner($classId, $year, $calendarWeek);
                     $substitutions = $this->planRepository->getSubstitutionsForClassWeekAsPlaner($classId, $year, $calendarWeek);
                 } elseif ($teacherId) {
                     $timetable = $this->planRepository->getTimetableForTeacherAsPlaner($teacherId, $year, $calendarWeek);
                     $substitutions = $this->planRepository->getSubstitutionsForTeacherWeekAsPlaner($teacherId, $year, $calendarWeek);
                 }
                 
                 // NEU: Lade Abwesenheiten für die ausgewählte Woche
                 $dto = new DateTime();
                 $dto->setISODate($year, $calendarWeek, 1);
                 $startDate = $dto->format('Y-m-d');
                 $dto->setISODate($year, $calendarWeek, 7); // Bis Sonntag
                 $endDate = $dto->format('Y-m-d');
                 $absencesData = $this->absenceRepo->getAbsencesForDateRange($startDate, $endDate);

             } elseif (($classId || $teacherId) && (!$year || !$calendarWeek)) {
                 // Fallback to current week if year/week are missing but entity is selected
                 $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                 $year = (int)$today->format('o');
                 $calendarWeek = (int)$today->format('W');
                 $publishStatus = $this->planRepository->getPublishStatus($year, $calendarWeek);

                 if ($classId) {
                     $timetable = $this->planRepository->getTimetableForClassAsPlaner($classId, $year, $calendarWeek);
                     $substitutions = $this->planRepository->getSubstitutionsForClassWeekAsPlaner($classId, $year, $calendarWeek);
                 } elseif ($teacherId) {
                     $timetable = $this->planRepository->getTimetableForTeacherAsPlaner($teacherId, $year, $calendarWeek);
                     $substitutions = $this->planRepository->getSubstitutionsForTeacherWeekAsPlaner($teacherId, $year, $calendarWeek);
                 }
                 
                 // NEU: Lade Abwesenheiten auch hier
                 $dto = new DateTime();
                 $dto->setISODate($year, $calendarWeek, 1);
                 $startDate = $dto->format('Y-m-d');
                 $dto->setISODate($year, $calendarWeek, 7);
                 $endDate = $dto->format('Y-m-d');
                 $absencesData = $this->absenceRepo->getAbsencesForDateRange($startDate, $endDate);
             }


             echo json_encode(['success' => true, 'data' => array_merge($baseData, [
                 'timetable' => $timetable,
                 'substitutions' => $substitutions,
                 'publishStatus' => $publishStatus,
                 'absences' => $absencesData // NEU: Abwesenheiten immer mitsenden
             ])], JSON_THROW_ON_ERROR);
        };
        // Führe den Callback mit dem API Request Handler aus (GET Request)
        $this->handleApiRequest($callback, '', '', true); // true = $isGetRequest
    }


    // --- Methoden zum Speichern/Löschen (POST requests) ---
    public function saveEntry()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!$data) throw new Exception("Ungültige Daten empfangen.");
            // The createOrUpdateEntry now potentially returns data (like generated block_id)
            $resultData = $this->planRepository->createOrUpdateEntry($data);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Eintrag erfolgreich gespeichert.', 'data' => $resultData],
                'log_target_id' => $data['entry_id'] ?? $resultData['entry_ids'][0] ?? null,
                'log_details' => $data // Logge die übermittelten Daten
            ];
        }, 'save_entry', 'timetable_entry');
    }

    public function deleteEntry()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $entryId = $data['entry_id'] ?? null;
            $blockId = $data['block_id'] ?? null;
            $logTargetId = null;
            $logDetails = $data;

            if ($blockId) {
                $this->planRepository->deleteEntryBlock($blockId);
                $message = 'Block erfolgreich gelöscht.';
                $logTargetId = $blockId; // Logge die Block-ID
            } elseif ($entryId && filter_var($entryId, FILTER_VALIDATE_INT)) {
                $this->planRepository->deleteEntry((int)$entryId);
                $message = 'Eintrag erfolgreich gelöscht.';
                $logTargetId = $entryId; // Logge die Entry-ID
            } else {
                throw new Exception("Ungültige Eintrags- oder Block-ID.");
            }

            return [
                'json_response' => ['success' => true, 'message' => $message],
                'log_target_id' => $logTargetId,
                'log_details' => $logDetails
            ];
        }, 'delete_entry', 'timetable_entry');
    }

    public function saveSubstitution()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!$data) throw new Exception("Ungültige Daten empfangen.");
            $resultData = $this->planRepository->createOrUpdateSubstitution($data);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Vertretung erfolgreich gespeichert.', 'data' => $resultData],
                'log_target_id' => $resultData['substitution_id'] ?? $data['substitution_id'] ?? null,
                'log_details' => $data
            ];
        }, 'save_substitution', 'substitution');
    }

    public function deleteSubstitution()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $id = $data['substitution_id'] ?? null;
            if (!filter_var($id, FILTER_VALIDATE_INT)) throw new Exception("Ungültige Vertretungs-ID.");
            $this->planRepository->deleteSubstitution((int)$id);
            
            return [
                'json_response' => ['success' => true, 'message' => 'Vertretung erfolgreich gelöscht.'],
                'log_target_id' => $id,
                'log_details' => $data
            ];
        }, 'delete_substitution', 'substitution');
    }

    // --- Methoden für Veröffentlichung (POST requests) ---

    public function publish()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            $target = $data['target'] ?? null; // 'student' or 'teacher'
            $userId = $_SESSION['user_id'];

            if (!$year || !$week || !in_array($target, ['student', 'teacher'])) {
                throw new Exception("Ungültige Parameter für Veröffentlichung.");
            }

            $success = $this->planRepository->publishWeek($year, $week, $target, $userId);

            if ($success) {
                $newStatus = $this->planRepository->getPublishStatus($year, $week); // Get updated status
                return [
                    'json_response' => [
                        'success' => true,
                        'message' => "Stundenplan KW $week/$year für " . ($target === 'student' ? 'Schüler' : 'Lehrer') . " veröffentlicht.",
                        'data' => ['publishStatus' => $newStatus] // Send updated status back
                    ],
                    'log_target_id' => null, // No specific entry ID
                    'log_details' => ['year' => $year, 'week' => $week, 'target' => $target]
                ];
            } else {
                throw new Exception("Veröffentlichung fehlgeschlagen.");
            }
        }, 'publish_week', 'system');
    }


    public function unpublish()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            $target = $data['target'] ?? null;

            if (!$year || !$week || !in_array($target, ['student', 'teacher'])) {
                throw new Exception("Ungültige Parameter.");
            }

            $success = $this->planRepository->unpublishWeek($year, $week, $target);

            if ($success) {
                 $newStatus = $this->planRepository->getPublishStatus($year, $week); // Get updated status
                return [
                    'json_response' => [
                        'success' => true,
                        'message' => "Veröffentlichung KW $week/$year für " . ($target === 'student' ? 'Schüler' : 'Lehrer') . " zurückgenommen.",
                        'data' => ['publishStatus' => $newStatus] // Send updated status back
                    ],
                    'log_target_id' => null,
                    'log_details' => ['year' => $year, 'week' => $week, 'target' => $target]
                ];
            } else {
                throw new Exception("Zurücknahme fehlgeschlagen.");
            }
        }, 'unpublish_week', 'system');
    }

     /**
     * API: Holt den aktuellen Veröffentlichungsstatus für eine Woche.
     * (GET request - no CSRF needed)
     */
     public function getStatus() {
         Security::requireRole(['planer', 'admin']);
         $callback = function() {
             $year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
             $week = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);

             if (!$year || !$week) {
                 throw new Exception("Jahr und Woche erforderlich.");
             }
             $status = $this->planRepository->getPublishStatus($year, $week);
             echo json_encode(['success' => true, 'data' => ['publishStatus' => $status]], JSON_THROW_ON_ERROR); // Wrap status
         };
         $this->handleApiRequest($callback, '', '', true); // Mark as GET request
     }

    /**
     * API-Endpunkt für die Echtzeit-Konfliktprüfung.
     * handleApiRequest fängt die Exception vom Repository (falls Konflikte)
     * und gibt einen 409-Statuscode mit den Konfliktmeldungen zurück.
     */
    public function checkConflictsApi()
    {
        Security::requireRole(['planer', 'admin']);
        
        // Verwende handleApiRequest für CSRF und Fehlerbehandlung, aber protokolliere diese Lese-Aktion nicht.
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!$data) {
                throw new Exception("Keine Daten für Konfliktprüfung empfangen.");
            }

            // Validierung (einfach)
            if (empty($data['year']) || empty($data['calendar_week']) || empty($data['day_of_week']) || empty($data['start_period_number']) || empty($data['end_period_number'])) {
                throw new Exception("Unvollständige Daten für Konfliktprüfung.");
            }
            // class_id '0' oder null ist im Lehrermodus erlaubt
            if (!isset($data['class_id'])) {
                throw new Exception("Fehlende class_id für Konfliktprüfung.");
            }

            $excludeEntryId = !empty($data['entry_id']) ? (int)$data['entry_id'] : null;
            $excludeBlockId = !empty($data['block_id']) ? (string)$data['block_id'] : null;

            // Ruft die Repository-Methode auf.
            // Diese wirft bei Konflikten eine Exception, die von handleApiRequest gefangen wird.
            $conflicts = $this->planRepository->checkConflicts($data, $excludeEntryId, $excludeBlockId);

            // Wenn checkConflicts *keine* Exception wirft, gab es keine Konflikte.
            return [
                'json_response' => ['success' => true, 'conflicts' => []]
                // Keine Log-Infos, da dies eine Lese-Aktion ist
            ];
        }); // Keine Log-Parameter übergeben
    }

    /**
     * API-Endpunkt zum Kopieren einer Woche.
     */
    public function copyWeek()
    {
        Security::requireRole(['planer', 'admin']);
        // Dies ist KEINE GET-Anfrage, CSRF wird in handleApiRequest geprüft
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

            // Validierung der Eingabedaten
            $sourceYear = filter_var($data['sourceYear'] ?? null, FILTER_VALIDATE_INT);
            $sourceWeek = filter_var($data['sourceWeek'] ?? null, FILTER_VALIDATE_INT);
            $targetYear = filter_var($data['targetYear'] ?? null, FILTER_VALIDATE_INT);
            $targetWeek = filter_var($data['targetWeek'] ?? null, FILTER_VALIDATE_INT);
            $classId = filter_var($data['classId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $teacherId = filter_var($data['teacherId'] ?? null, FILTER_VALIDATE_INT) ?: null;

            if (!$sourceYear || !$sourceWeek || !$targetYear || !$targetWeek) {
                throw new Exception("Quell- und Zielwoche sind erforderlich.");
            }
            if ($classId === null && $teacherId === null) {
                throw new Exception("Klasse oder Lehrer erforderlich.");
            }

            // Aufruf der Repository-Methode
            $copiedCount = $this->planRepository->copyWeekData(
                $sourceYear,
                $sourceWeek,
                $targetYear,
                $targetWeek,
                $classId,
                $teacherId
            );

            return [
                'json_response' => [
                    'success' => true,
                    'message' => "Woche erfolgreich kopiert. {$copiedCount} Einträge wurden in KW {$targetWeek}/{$targetYear} eingefügt.",
                    'copiedCount' => $copiedCount
                ],
                'log_target_id' => null, // Kein spezifisches Objekt, Systemaktion
                'log_details' => $data // Logge die Quell- und Zieldaten
            ];
        }, 'copy_week', 'system');
    }

    // --- NEUE API METHODEN FÜR VORLAGEN ---

    /**
     * API: Holt alle verfügbaren Vorlagen (GET - no CSRF needed).
     */
    public function getTemplates()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $templates = $this->planRepository->getTemplates();
            echo json_encode(['success' => true, 'data' => $templates], JSON_THROW_ON_ERROR);
        }, '', '', true); // Mark as GET request
    }

    /**
     * API: Erstellt eine neue Vorlage aus der aktuell angezeigten Woche (POST).
     */
    public function createTemplate()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '') ?: null;
            $sourceYear = filter_var($data['sourceYear'] ?? null, FILTER_VALIDATE_INT);
            $sourceWeek = filter_var($data['sourceWeek'] ?? null, FILTER_VALIDATE_INT);
            $sourceClassId = filter_var($data['sourceClassId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $sourceTeacherId = filter_var($data['sourceTeacherId'] ?? null, FILTER_VALIDATE_INT) ?: null;

            if (empty($name) || !$sourceYear || !$sourceWeek || ($sourceClassId === null && $sourceTeacherId === null)) {
                throw new Exception("Ungültige Daten zum Erstellen der Vorlage.");
            }

            // Quelldaten holen
            $sourceEntries = [];
            if ($sourceClassId) {
                $sourceEntries = $this->planRepository->getTimetableForClassAsPlaner($sourceClassId, $sourceYear, $sourceWeek);
            } elseif ($sourceTeacherId) {
                $sourceEntries = $this->planRepository->getTimetableForTeacherAsPlaner($sourceTeacherId, $sourceYear, $sourceWeek);
            }

            if (empty($sourceEntries)) {
                throw new Exception("Keine Stundenplandaten in der Quellwoche gefunden, um die Vorlage zu erstellen.");
            }

            // Vorlage erstellen
            $newTemplateId = $this->planRepository->createTemplate($name, $description, $sourceEntries);

            return [
                'json_response' => [
                    'success' => true,
                    'message' => "Vorlage '{$name}' erfolgreich erstellt.",
                    'data' => ['template_id' => $newTemplateId, 'name' => $name, 'description' => $description] // Neue Vorlage zurückgeben
                ],
                'log_target_id' => $newTemplateId,
                'log_details' => $data
            ];
        }, 'create_template_from_week', 'template');
    }

    /**
     * API: Wendet eine Vorlage auf die aktuell ausgewählte Woche an (POST).
     */
    public function applyTemplate()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

            $templateId = filter_var($data['templateId'] ?? null, FILTER_VALIDATE_INT);
            $targetYear = filter_var($data['targetYear'] ?? null, FILTER_VALIDATE_INT);
            $targetWeek = filter_var($data['targetWeek'] ?? null, FILTER_VALIDATE_INT);
            $targetClassId = filter_var($data['targetClassId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $targetTeacherId = filter_var($data['targetTeacherId'] ?? null, FILTER_VALIDATE_INT) ?: null;

            if (!$templateId || !$targetYear || !$targetWeek || ($targetClassId === null && $targetTeacherId === null)) {
                throw new Exception("Ungültige Daten zum Anwenden der Vorlage.");
            }

            // Vorlage anwenden
            $appliedCount = $this->planRepository->applyTemplateToWeek(
                $templateId,
                $targetYear,
                $targetWeek,
                $targetClassId,
                $targetTeacherId
            );
            
            return [
                'json_response' => [
                    'success' => true,
                    'message' => "Vorlage erfolgreich angewendet. {$appliedCount} Einträge wurden in KW {$targetWeek}/{$targetYear} eingefügt.",
                    'appliedCount' => $appliedCount
                ],
                'log_target_id' => $templateId,
                'log_details' => $data
            ];
        }, 'apply_template', 'template');
    }

    /**
     * API: Löscht eine Vorlage (POST).
     */
    public function deleteTemplate()
    {
        Security::requireRole(['planer', 'admin']);
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $templateId = filter_var($data['templateId'] ?? null, FILTER_VALIDATE_INT);

            if (!$templateId) {
                throw new Exception("Ungültige Vorlagen-ID.");
            }

            $success = $this->planRepository->deleteTemplate($templateId);

            if ($success) {
                return [
                    'json_response' => ['success' => true, 'message' => 'Vorlage erfolgreich gelöscht.'],
                    'log_target_id' => $templateId,
                    'log_details' => $data
                ];
            } else {
                throw new Exception("Fehler beim Löschen der Vorlage.");
            }
        }, 'delete_template', 'template');
    }
    
    /**
     * API: Lädt die Details (Stammdaten + Einträge) einer einzelnen Vorlage.
     * (GET request)
     */
    public function getTemplateDetails(int $templateId)
    {
        Security::requireRole(['planer', 'admin']);
        // Verwende den GET-Modus des Helpers (true)
        $this->handleApiRequest(function() use ($templateId) {
            $details = $this->planRepository->loadTemplateDetails($templateId);
            echo json_encode(['success' => true, 'data' => $details], JSON_THROW_ON_ERROR);
        }, '', '', true);
    }
    
    /**
     * API: Speichert eine Vorlage (neu oder Update) aus dem Editor.
     * (POST request)
     */
    public function saveTemplateDetails()
    {
        Security::requireRole(['planer', 'admin']);
        
        $this->handleApiRequest(function() {
            $data = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            
            // Validierung im Controller, bevor es an das Repo geht
            if (empty($data['name'])) {
                throw new Exception("Vorlagenname darf nicht leer sein.");
            }

            $savedTemplate = $this->planRepository->saveTemplateDetails($data);
            
            $action = empty($data['template_id']) ? 'create_template' : 'update_template';

            return [
                'json_response' => [
                    'success' => true,
                    'message' => 'Vorlage erfolgreich gespeichert.',
                    'data' => $savedTemplate // Gibt die gespeicherten Stammdaten (mit ID) zurück
                ],
                'log_target_id' => $savedTemplate['template_id'],
                'log_details' => [
                    'name' => $savedTemplate['name'],
                    'description' => $savedTemplate['description'],
                    'entries_count' => count($data['entries'] ?? [])
                ]
            ];

        }, 'save_template_details', 'template'); // Aktion wird intern bestimmt, Logging hier allgemein
    }


    // --- ENDE NEUE API METHODEN FÜR VORLAGEN ---
}