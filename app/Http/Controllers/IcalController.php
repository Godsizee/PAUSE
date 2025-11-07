<?php
// app/Http/Controllers/IcalController.php

// MODIFIZIERT:
// 1. Alle Repositories und Utils wurden entfernt.
// 2. IcalService wurde importiert und im Konstruktor instanziiert.
// 3. Alle privaten Hilfsmethoden (getWeekYear, processWeekData, formatAsIcs etc.) wurden entfernt.
// 4. Die generateFeed() Methode wurde komplett refaktorisiert:
//    - Sie ruft nur noch $this->icalService->generateFeed() auf.
//    - Sie kümmert sich um das Setzen der HTTP-Header.
//    - Sie fängt Exceptions vom Service ab und zeigt eine saubere Fehlermeldung an.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\PlanRepository;
use App\Repositories\AcademicEventRepository;
use App\Services\IcalService; // NEU: Service importieren
use Exception;

class IcalController
{
    // VERALTET: Repositories werden nicht mehr direkt benötigt
    // private UserRepository $userRepository;
    // private PlanRepository $planRepository;
    // private AcademicEventRepository $eventRepository;
    // private \PDO $pdo;
    // private array $settings;

    private IcalService $icalService; // NEU

    // VERALTET: Zeitdefinitionen wurden in den Service verschoben
    // private const PERIOD_TIMES = [ ... ];

    public function __construct()
    {
        // NEU: Service instanziieren und ihm seine Abhängigkeiten übergeben
        $pdo = Database::getInstance();
        $this->icalService = new IcalService(
            new UserRepository($pdo),
            new PlanRepository($pdo),
            new AcademicEventRepository($pdo),
            Utils::getSettings()
        );
        
        // VERALTETE Initialisierungen:
        // $this->pdo = Database::getInstance();
        // $this->userRepository = new UserRepository($this->pdo);
        // $this->planRepository = new PlanRepository($this->pdo);
        // $this->eventRepository = new AcademicEventRepository($this->pdo);
        // $this->settings = Utils::getSettings();
    }

    /**
     * Generiert den iCal-Feed, indem der IcalService aufgerufen wird.
     * Kümmert sich um das Senden der Header und der Antwort.
     *
     * @param string $token Der iCal-Token aus der URL.
     */
    public function generateFeed(string $token)
    {
        try {
            // 1. Die gesamte Logik an den Service delegieren
            $icsContent = $this->icalService->generateFeed($token);

            // 2. Header setzen (bei Erfolg)
            if (!headers_sent()) {
                header('Content-Type: text/calendar; charset=utf-8');
                header('Content-Disposition: inline; filename="pause_stundenplan.ics"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
            }

            // 3. Inhalt ausgeben
            echo $icsContent;
            exit;

        } catch (Exception $e) {
            // 4. Fehlerbehandlung (vom Service geworfen)
            $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            
            if (!headers_sent()) {
                http_response_code($statusCode);
                header('Content-Type: text/plain; charset=utf-8');
            }
            
            // Logge den serverseitigen Fehler
            if ($statusCode >= 500) {
                 error_log("Fehler bei iCal-Generierung: " . $e->getMessage());
            }

            // Zeige eine saubere Fehlermeldung für den Benutzer an
            echo "Fehler beim Generieren des Kalender-Feeds: " . $e->getMessage();
            exit;
        }
    }

    /**
     * VERALTET: Alle privaten Hilfsmethoden wurden in den IcalService verschoben.
     */
    // private function getWeekYear(...) { ... }
    // private function getWeekDateRange(...) { ... }
    // private function getDateForDayOfWeek(...) { ... }
    // private function processWeekData(...) { ... }
    // private function processAcademicEvents(...) { ... }
    // private function formatAsIcs(...) { ... }
    // private function escapeIcsString(...) { ... }

} // End class