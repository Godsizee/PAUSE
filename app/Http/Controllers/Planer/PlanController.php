<?php
namespace App\Http\Controllers\Planer;
use App\Core\Security;
use App\Core\Database;
use App\Repositories\PlanRepository;
use App\Repositories\StammdatenRepository;
use App\Repositories\TeacherAbsenceRepository;
use Exception;
use PDO;
use DateTime;
use DateTimeZone;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait; // NEU

class PlanController
{
    use ApiHandlerTrait; // NEU

    private PDO $pdo;
    private PlanRepository $planRepository;
    private StammdatenRepository $stammdatenRepository;
    private TeacherAbsenceRepository $absenceRepo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->planRepository = new PlanRepository($this->pdo);
        $this->stammdatenRepository = new StammdatenRepository($this->pdo);
        $this->absenceRepo = new TeacherAbsenceRepository($this->pdo);
    }

    public function index()
    {
        Security::requireRole(['planer', 'admin']);
        global $config;
        $config = Database::getConfig();
        $page_title = 'Stundenplan-Verwaltung';
        $body_class = 'planer-dashboard-body';
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/planer/dashboard.php';
    }

    // ENTFERNT: Lokale handleApiRequest Methode

    public function getTimetableData()
    {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $classId = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);
            $teacherId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $calendarWeek = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            $date = $data['date'] ?? null;
            if ($date && (DateTime::createFromFormat('Y-m-d', $date) === false)) {
                throw new Exception("Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.");
            }
            $baseData = [];
            $absencesData = [];
            if (!$classId && !$teacherId && !$year && !$calendarWeek) {
                $baseData = [
                    'classes' => $this->stammdatenRepository->getClasses(),
                    'teachers' => $this->stammdatenRepository->getTeachers(),
                    'subjects' => $this->stammdatenRepository->getSubjects(),
                    'rooms' => $this->stammdatenRepository->getRooms(),
                    'templates' => $this->planRepository->getTemplates(),
                ];
                $today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
                $startDate = $today->format('Y-m-01');
                $endDate = $today->modify('+3 months')->format('Y-m-t');
                $absencesData = $this->absenceRepo->getAbsencesForDateRange($startDate, $endDate);
                $baseData['absences'] = $absencesData;
            }
            $timetable = [];
            $substitutions = [];
            $publishStatus = ['student' => false, 'teacher' => false];
            if (($classId || $teacherId) && $year && $calendarWeek) {
                $publishStatus = $this->planRepository->getPublishStatus($year, $calendarWeek);
                if ($classId) {
                    $timetable = $this->planRepository->getTimetableForClassAsPlaner($classId, $year, $calendarWeek);
                    $substitutions = $this->planRepository->getSubstitutionsForClassWeekAsPlaner($classId, $year, $calendarWeek);
                } elseif ($teacherId) {
                    $timetable = $this->planRepository->getTimetableForTeacherAsPlaner($teacherId, $year, $calendarWeek);
                    $substitutions = $this->planRepository->getSubstitutionsForTeacherWeekAsPlaner($teacherId, $year, $calendarWeek);
                }
                $dto = new DateTime();
                $dto->setISODate($year, $calendarWeek, 1);
                $startDate = $dto->format('Y-m-d');
                $dto->setISODate($year, $calendarWeek, 7);
                $endDate = $dto->format('Y-m-d');
                $absencesData = $this->absenceRepo->getAbsencesForDateRange($startDate, $endDate);
            } elseif (($classId || $teacherId) && (!$year || !$calendarWeek)) {
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
                'absences' => $absencesData
            ])], JSON_THROW_ON_ERROR);
            
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function saveEntry()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            if (!$data) throw new Exception("Ungültige Daten empfangen.");
            $resultData = $this->planRepository->createOrUpdateEntry($data);
            return [
                'json_response' => ['success' => true, 'message' => 'Eintrag erfolgreich gespeichert.', 'data' => $resultData],
                'log_action' => 'save_entry',
                'log_target_type' => 'timetable_entry',
                'log_target_id' => $data['entry_id'] ?? $resultData['entry_ids'][0] ?? null,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function deleteEntry()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $entryId = $data['entry_id'] ?? null;
            $blockId = $data['block_id'] ?? null;
            $logTargetId = null;
            $logDetails = $data;
            if ($blockId) {
                $this->planRepository->deleteEntryBlock($blockId);
                $message = 'Block erfolgreich gelöscht.';
                $logTargetId = $blockId;
            } elseif ($entryId && filter_var($entryId, FILTER_VALIDATE_INT)) {
                $this->planRepository->deleteEntry((int)$entryId);
                $message = 'Eintrag erfolgreich gelöscht.';
                $logTargetId = $entryId;
            } else {
                throw new Exception("Ungültige Eintrags- oder Block-ID.");
            }
            return [
                'json_response' => ['success' => true, 'message' => $message],
                'log_action' => 'delete_entry',
                'log_target_type' => 'timetable_entry',
                'log_target_id' => $logTargetId,
                'log_details' => $logDetails
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function saveSubstitution()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            if (!$data) throw new Exception("Ungültige Daten empfangen.");
            $resultData = $this->planRepository->createOrUpdateSubstitution($data);
            return [
                'json_response' => ['success' => true, 'message' => 'Vertretung erfolgreich gespeichert.', 'data' => $resultData],
                'log_action' => 'save_substitution',
                'log_target_type' => 'substitution',
                'log_target_id' => $resultData['substitution_id'] ?? $data['substitution_id'] ?? null,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function deleteSubstitution()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $id = $data['substitution_id'] ?? null;
            if (!filter_var($id, FILTER_VALIDATE_INT)) throw new Exception("Ungültige Vertretungs-ID.");
            $this->planRepository->deleteSubstitution((int)$id);
            return [
                'json_response' => ['success' => true, 'message' => 'Vertretung erfolgreich gelöscht.'],
                'log_action' => 'delete_substitution',
                'log_target_type' => 'substitution',
                'log_target_id' => $id,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function publish()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            $target = $data['target'] ?? null;
            $userId = $_SESSION['user_id'];
            if (!$year || !$week || !in_array($target, ['student', 'teacher'])) {
                throw new Exception("Ungültige Parameter für Veröffentlichung.");
            }
            $success = $this->planRepository->publishWeek($year, $week, $target, $userId);
            if ($success) {
                $newStatus = $this->planRepository->getPublishStatus($year, $week);
                return [
                    'json_response' => [
                        'success' => true,
                        'message' => "Stundenplan KW $week/$year für " . ($target === 'student' ? 'Schüler' : 'Lehrer') . " veröffentlicht.",
                        'data' => ['publishStatus' => $newStatus]
                    ],
                    'log_action' => 'publish_week',
                    'log_target_type' => 'system',
                    'log_details' => ['year' => $year, 'week' => $week, 'target' => $target]
                ];
            } else {
                throw new Exception("Veröffentlichung fehlgeschlagen.");
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function unpublish()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            $target = $data['target'] ?? null;
            if (!$year || !$week || !in_array($target, ['student', 'teacher'])) {
                throw new Exception("Ungültige Parameter.");
            }
            $success = $this->planRepository->unpublishWeek($year, $week, $target);
            if ($success) {
                $newStatus = $this->planRepository->getPublishStatus($year, $week);
                return [
                    'json_response' => [
                        'success' => true,
                        'message' => "Veröffentlichung KW $week/$year für " . ($target === 'student' ? 'Schüler' : 'Lehrer') . " zurückgenommen.",
                        'data' => ['publishStatus' => $newStatus]
                    ],
                    'log_action' => 'unpublish_week',
                    'log_target_type' => 'system',
                    'log_details' => ['year' => $year, 'week' => $week, 'target' => $target]
                ];
            } else {
                throw new Exception("Zurücknahme fehlgeschlagen.");
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function getStatus() {
        $this->handleApiRequest(function($data) { // $data kommt von $_GET
            $year = filter_var($data['year'] ?? null, FILTER_VALIDATE_INT);
            $week = filter_var($data['week'] ?? null, FILTER_VALIDATE_INT);
            if (!$year || !$week) {
                throw new Exception("Jahr und Woche erforderlich.");
            }
            $status = $this->planRepository->getPublishStatus($year, $week);
            echo json_encode(['success' => true, 'data' => ['publishStatus' => $status]], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function checkConflictsApi()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            if (!$data) {
                throw new Exception("Keine Daten für Konfliktprüfung empfangen.");
            }
            if (empty($data['year']) || empty($data['calendar_week']) || empty($data['day_of_week']) || empty($data['start_period_number']) || empty($data['end_period_number'])) {
                throw new Exception("Unvollständige Daten für Konfliktprüfung.");
            }
            if (!isset($data['class_id'])) {
                throw new Exception("Fehlende class_id für Konfliktprüfung.");
            }
            $excludeEntryId = !empty($data['entry_id']) ? (int)$data['entry_id'] : null;
            $excludeBlockId = !empty($data['block_id']) ? (string)$data['block_id'] : null;
            
            // Die checkConflicts Methode wirft eine Exception bei Konflikten
            $this->planRepository->checkConflicts($data, $excludeEntryId, $excludeBlockId);
            
            // Wenn wir hier ankommen, gab es keine Konflikte
            return [
                'json_response' => ['success' => true, 'conflicts' => []]
                // Kein Logging für reine Prüfungen
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function copyWeek()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
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
                'log_action' => 'copy_week',
                'log_target_type' => 'system',
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function getTemplates()
    {
        $this->handleApiRequest(function() {
            $templates = $this->planRepository->getTemplates();
            echo json_encode(['success' => true, 'data' => $templates], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function createTemplate()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '') ?: null;
            $sourceYear = filter_var($data['sourceYear'] ?? null, FILTER_VALIDATE_INT);
            $sourceWeek = filter_var($data['sourceWeek'] ?? null, FILTER_VALIDATE_INT);
            $sourceClassId = filter_var($data['sourceClassId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $sourceTeacherId = filter_var($data['sourceTeacherId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if (empty($name) || !$sourceYear || !$sourceWeek || ($sourceClassId === null && $sourceTeacherId === null)) {
                throw new Exception("Ungültige Daten zum Erstellen der Vorlage.");
            }
            $sourceEntries = [];
            if ($sourceClassId) {
                $sourceEntries = $this->planRepository->getTimetableForClassAsPlaner($sourceClassId, $sourceYear, $sourceWeek);
            } elseif ($sourceTeacherId) {
                $sourceEntries = $this->planRepository->getTimetableForTeacherAsPlaner($sourceTeacherId, $sourceYear, $sourceWeek);
            }
            if (empty($sourceEntries)) {
                throw new Exception("Keine Stundenplandaten in der Quellwoche gefunden, um die Vorlage zu erstellen.");
            }
            $newTemplateId = $this->planRepository->createTemplate($name, $description, $sourceEntries);
            return [
                'json_response' => [
                    'success' => true,
                    'message' => "Vorlage '{$name}' erfolgreich erstellt.",
                    'data' => ['template_id' => $newTemplateId, 'name' => $name, 'description' => $description]
                ],
                'log_action' => 'create_template_from_week',
                'log_target_type' => 'template',
                'log_target_id' => $newTemplateId,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function applyTemplate()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $templateId = filter_var($data['templateId'] ?? null, FILTER_VALIDATE_INT);
            $targetYear = filter_var($data['targetYear'] ?? null, FILTER_VALIDATE_INT);
            $targetWeek = filter_var($data['targetWeek'] ?? null, FILTER_VALIDATE_INT);
            $targetClassId = filter_var($data['targetClassId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $targetTeacherId = filter_var($data['targetTeacherId'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if (!$templateId || !$targetYear || !$targetWeek || ($targetClassId === null && $targetTeacherId === null)) {
                throw new Exception("Ungültige Daten zum Anwenden der Vorlage.");
            }
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
                'log_action' => 'apply_template',
                'log_target_type' => 'template',
                'log_target_id' => $templateId,
                'log_details' => $data
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function deleteTemplate()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            $templateId = filter_var($data['templateId'] ?? null, FILTER_VALIDATE_INT);
            if (!$templateId) {
                throw new Exception("Ungültige Vorlagen-ID.");
            }
            $success = $this->planRepository->deleteTemplate($templateId);
            if ($success) {
                return [
                    'json_response' => ['success' => true, 'message' => 'Vorlage erfolgreich gelöscht.'],
                    'log_action' => 'delete_template',
                    'log_target_type' => 'template',
                    'log_target_id' => $templateId,
                    'log_details' => $data
                ];
            } else {
                throw new Exception("Fehler beim Löschen der Vorlage.");
            }
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function getTemplateDetails(int $templateId)
    {
        $this->handleApiRequest(function() use ($templateId) {
            $details = $this->planRepository->loadTemplateDetails($templateId);
            echo json_encode(['success' => true, 'data' => $details], JSON_THROW_ON_ERROR);
            return ['is_get_request' => true];
        }, [
            'inputType' => 'get',
            'checkRole' => ['planer', 'admin']
        ]);
    }

    public function saveTemplateDetails()
    {
        $this->handleApiRequest(function($data) { // $data kommt von JSON
            if (empty($data['name'])) {
                throw new Exception("Vorlagenname darf nicht leer sein.");
            }
            $savedTemplate = $this->planRepository->saveTemplateDetails($data);
            $action = empty($data['template_id']) ? 'create_template' : 'update_template';
            return [
                'json_response' => [
                    'success' => true,
                    'message' => 'Vorlage erfolgreich gespeichert.',
                    'data' => $savedTemplate
                ],
                'log_action' => 'save_template_details',
                'log_target_type' => 'template',
                'log_target_id' => $savedTemplate['template_id'],
                'log_details' => [
                    'name' => $savedTemplate['name'],
                    'description' => $savedTemplate['description'],
                    'entries_count' => count($data['entries'] ?? [])
                ]
            ];
        }, [
            'inputType' => 'json',
            'checkRole' => ['planer', 'admin']
        ]);
    }
}