<?php
// app/Http/Controllers/SingleEventController.php

// MODIFIZIERT:
// 1. Abhängigkeiten (Repositories) entfernt.
// 2. SingleIcalService importiert und im Konstruktor instanziiert.
// 3. Die Methode generateIcs() wurde komplett refaktorisiert:
//    - Sie ruft nur noch $this->icalService->generateSingleIcs() auf.
//    - Sie kümmert sich um das Setzen der HTTP-Header (basierend auf der Service-Antwort).
//    - Sie fängt Exceptions vom Service ab und zeigt eine saubere Fehlermeldung an.
// 4. Alle privaten Hilfsmethoden (getAcademicEventData, formatAsIcs etc.) wurden entfernt.

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AcademicEventRepository;
use App\Repositories\PlanRepository;
use App\Services\SingleIcalService; // NEU: Service importieren
use Exception;
use PDO;

/**
 * Erstellt einzelne .ics-Dateien für spezifische Events.
 * MODIFIZIERT: Nutzt jetzt den SingleIcalService für die Logik.
 */
class SingleEventController
{
    // VERALTET: Repositories werden nicht mehr direkt benötigt
    // private PDO $pdo;
    // private AcademicEventRepository $eventRepo;
    // private PlanRepository $planRepo;
    
    private SingleIcalService $icalService; // NEU

    // VERALTET: Zeitdefinitionen und Zeitzone wurden in den Service verschoben
    // private DateTimeZone $timezone;
    // private const PERIOD_TIMES = [ ... ];

    public function __construct()
    {
        // NEU: Service instanziieren und Abhängigkeiten übergeben
        $pdo = Database::getInstance();
        $this->icalService = new SingleIcalService(
            new AcademicEventRepository($pdo),
            new PlanRepository($pdo)
        );
    }

    /**
     * Generiert eine .ics-Datei für ein einzelnes Event (Aufgabe, Klausur oder Sonderevent).
     *
     * @param string $type Typ des Events ('acad' oder 'sub')
     * @param int $id Die ID des Events
     */
    public function generateIcs(string $type, int $id)
    {
        try {
            // 1. Benutzer muss angemeldet sein
            Security::requireLogin();

            // 2. Logik an den Service delegieren
            $result = $this->icalService->generateSingleIcs($type, $id);
            
            $icsContent = $result['content'];
            $filename = $result['filename'];

            // 3. HTTP-Header für .ics-Download senden
            if (!headers_sent()) {
                header('Content-Type: text/calendar; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
            }
            
            // 4. Inhalt ausgeben
            echo $icsContent;
            exit;

        } catch (Exception $e) {
            // 5. Fehlerbehandlung
            $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            
            if (!headers_sent()) {
                 http_response_code($statusCode);
                 header('Content-Type: text/plain; charset=utf-8');
            }
           
            if ($statusCode >= 500) {
                 error_log("Fehler bei Einzel-ICS-Generierung: " . $e->getMessage());
            }

            // Zeige eine einfache Fehlermeldung statt die App abzustürzen
            die("Fehler beim Erstellen der Kalenderdatei: " . htmlspecialchars($e->getMessage()));
        }
    }

    /**
     * VERALTET: Alle privaten Hilfsmethoden wurden in den SingleIcalService verschoben.
     */
    // private function getAcademicEventData(int $id): ?array { ... }
    // private function getSubstitutionEventData(int $id): ?array { ... }
    // private function formatAsIcs(array $event): string { ... }
    // private function escapeIcsString(?string $string): string { ... }
}