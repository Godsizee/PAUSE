<?php
// app/Http/Controllers/SingleEventController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\AcademicEventRepository;
use App\Repositories\PlanRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PDO;

/**
 * Erstellt einzelne .ics-Dateien für spezifische Events.
 */
class SingleEventController
{
    private PDO $pdo;
    private AcademicEventRepository $eventRepo;
    private PlanRepository $planRepo;
    private DateTimeZone $timezone;

    // Zeitdefinitionen (konsistent mit IcalController)
    private const PERIOD_TIMES = [
        1 => ['start' => '0800', 'end' => '0845'],
        2 => ['start' => '0855', 'end' => '0940'],
        3 => ['start' => '0940', 'end' => '1025'],
        4 => ['start' => '1035', 'end' => '1120'],
        5 => ['start' => '1120', 'end' => '1205'],
        6 => ['start' => '1305', 'end' => '1350'],
        7 => ['start' => '1350', 'end' => '1435'],
        8 => ['start' => '1445', 'end' => '1530'],
        9 => ['start' => '1530', 'end' => '1615'],
        10 => ['start' => '1625', 'end' => '1710'],
    ];

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->eventRepo = new AcademicEventRepository($this->pdo);
        $this->planRepo = new PlanRepository($this->pdo);
        $this->timezone = new DateTimeZone('Europe/Berlin');
    }

    /**
     * Generiert eine .ics-Datei für ein einzelnes Event (Aufgabe, Klausur oder Sonderevent).
     *
     * @param string $type Typ des Events ('acad' für academic_events, 'sub' für substitutions)
     * @param int $id Die ID des Events
     */
    public function generateIcs(string $type, int $id)
    {
        try {
            Security::requireLogin(); // Benutzer muss angemeldet sein

            $eventData = null;
            if ($type === 'acad') {
                $eventData = $this->getAcademicEventData($id);
            } elseif ($type === 'sub') {
                $eventData = $this->getSubstitutionEventData($id);
            } else {
                throw new Exception("Ungültiger Event-Typ.", 400);
            }

            if (!$eventData) {
                throw new Exception("Termin nicht gefunden.", 404);
            }
            
            // Berechtigungsprüfung (vereinfacht):
            // TODO: Prüfen, ob der User (Schüler/Lehrer) dieses Event überhaupt sehen darf.
            // Für MVP lassen wir diese komplexe Prüfung weg, da Security::requireLogin() gilt.

            $icsContent = $this->formatAsIcs($eventData);
            $filename = str_replace(' ', '_', $eventData['summary'] ?? 'termin') . '.ics';

            // HTTP-Header für .ics-Download senden
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $icsContent;
            exit;

        } catch (Exception $e) {
            http_response_code($e->getCode() ?: 500);
            // Zeige eine einfache Fehlermeldung statt die App abzustürzen
            die("Fehler beim Erstellen der Kalenderdatei: " . htmlspecialchars($e->getMessage()));
        }
    }

    /** Holt und formatiert Daten für Aufgaben/Klausuren */
    private function getAcademicEventData(int $id): ?array
    {
        $event = $this->eventRepo->getEventById($id); // Diese Methode ist jetzt public
        if (!$event) return null;

        $dateObj = new DateTimeImmutable($event['due_date'] . ' 00:00:00', $this->timezone);
        $dtStart = $dateObj;
        $dtEnd = $dateObj->modify('+1 day');
        $dtStartFormat = 'Ymd';
        $dtEndFormat = 'Ymd';
        $timeInfo = "";
        $location = ""; // Aufgaben/Klausuren haben keinen Ort im Schema

        $icon = 'ℹ️'; $prefix = 'Info';
        if ($event['event_type'] === 'klausur') { $icon = '🎓'; $prefix = 'Klausur'; }
        if ($event['event_type'] === 'aufgabe') { $icon = '📚'; $prefix = 'Aufgabe'; }

        $summary = "{$icon} {$prefix}: " . ($event['title'] ?? 'Eintrag');
        if ($event['subject_shortcut']) {
            $summary .= " (" . $event['subject_shortcut'] . ")";
        }

        $description = "Typ: " . ucfirst($event['event_type']) . "\n";
        $description .= "Fach: " . ($event['subject_shortcut'] ?? '-') . "\n";
        $description .= "Klasse: " . ($event['class_name'] ?? '?') . "\n";
        $description .= "Datum: " . $dateObj->format('d.m.Y') . $timeInfo . "\n";
        if ($event['description']) {
            $description .= "\nBeschreibung:\n" . $event['description'];
        }

        return [
            'uid' => 'acad-' . $event['event_id'],
            'dtStart' => $dtStart,
            'dtEnd' => $dtEnd,
            'dtStartFormat' => $dtStartFormat,
            'dtEndFormat' => $dtEndFormat,
            'summary' => $summary,
            'location' => $location,
            'description' => trim($description),
            'status' => 'CONFIRMED',
        ];
    }

    /** Holt und formatiert Daten für Sonderevents (aus Vertretungen) */
    private function getSubstitutionEventData(int $id): ?array
    {
        $sub = $this->planRepo->getSubstitutionById($id); // Diese Methode ist public
        if (!$sub || $sub['substitution_type'] !== 'Sonderevent') {
            // Nur Sonderevents zulassen
            return null; 
        }

        $dateObj = new DateTimeImmutable($sub['date'] . ' 00:00:00', $this->timezone);
        $period = (int)$sub['period_number'];
        $times = self::PERIOD_TIMES[$period] ?? null;

        if (!$times) {
            throw new Exception("Ungültige Zeit für Sonderevent.");
        }

        $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
        $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));
        $dtStartFormat = 'Ymd\THis';
        $dtEndFormat = 'Ymd\THis';
        
        $summary = "Sonderevent: " . ($sub['comment'] ?: $sub['new_subject_shortcut'] ?: 'Termin');
        $location = $sub['new_room_name'] ?? '';
        
        $description = "Sonderevent\n";
        $description .= "Klasse: " . ($sub['class_name'] ?? '?') . "\n";
        $description .= "Raum: " . ($location ?: '?') . "\n";
        if ($sub['comment']) {
            $description .= "Details: " . $sub['comment'];
        }

        return [
            'uid' => 'sub-' . $sub['substitution_id'],
            'dtStart' => $dtStart,
            'dtEnd' => $dtEnd,
            'dtStartFormat' => $dtStartFormat,
            'dtEndFormat' => $dtEndFormat,
            'summary' => $summary,
            'location' => $location,
            'description' => trim($description),
            'status' => 'CONFIRMED',
        ];
    }

    /** Formatiert ein einzelnes Event als vollständigen iCal-String */
    private function formatAsIcs(array $event): string
    {
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//PMI//PAUSE Einzeltermin v1.0//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";

        // Zeitzonen-Definition (wichtig für zeitbasierte Termine)
        $ics .= "BEGIN:VTIMEZONE\r\n";
        $ics .= "TZID:Europe/Berlin\r\n";
        $ics .= "X-LIC-LOCATION:Europe/Berlin\r\n";
        $ics .= "BEGIN:DAYLIGHT\r\n";
        $ics .= "TZOFFSETFROM:+0100\r\n";
        $ics .= "TZOFFSETTO:+0200\r\n";
        $ics .= "TZNAME:CEST\r\n";
        $ics .= "DTSTART:19700329T020000\r\n";
        $ics .= "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n";
        $ics .= "END:DAYLIGHT\r\n";
        $ics .= "BEGIN:STANDARD\r\n";
        $ics .= "TZOFFSETFROM:+0200\r\n";
        $ics .= "TZOFFSETTO:+0100\r\n";
        $ics .= "TZNAME:CET\r\n";
        $ics .= "DTSTART:19701025T030000\r\n";
        $ics .= "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n";
        $ics .= "END:STANDARD\r\n";
        $ics .= "END:VTIMEZONE\r\n";

        $nowUtc = gmdate('Ymd\THis\Z');

        // Event-Daten
        $dtStart = $event['dtStart'];
        $dtEnd = $event['dtEnd'];
        $dtStartFormat = $event['dtStartFormat'] ?? 'Ymd\THis';
        $dtEndFormat = $event['dtEndFormat'] ?? 'Ymd\THis';
        $dtStartString = $dtStart->format($dtStartFormat);
        $dtEndString = $dtEnd->format($dtEndFormat);
        $datePrefix = ($dtStartFormat === 'Ymd') ? ';VALUE=DATE' : ';TZID=Europe/Berlin';
        
        if ($dtStartFormat === 'Ymd\THis' && $dtEndFormat === 'Ymd') {
             $dtEndString = $dtEnd->format('Ymd\THis'); // Korrigiere Endformat bei Zeit-Events
        }

        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $event['uid'] . '-' . $dtStart->format('YmdHis') . "@pause.pmi\r\n";
        $ics .= "DTSTAMP:" . $nowUtc . "\r\n";
        $ics .= "DTSTART{$datePrefix}:" . $dtStartString . "\r\n";
        $ics .= "DTEND{$datePrefix}:" . $dtEndString . "\r\n";
        $ics .= "SUMMARY:" . $this->escapeIcsString($event['summary']) . "\r\n";
        if (!empty($event['location'])) {
            $ics .= "LOCATION:" . $this->escapeIcsString($event['location']) . "\r\n";
        }
        if (!empty($event['description'])) {
            $ics .= "DESCRIPTION:" . $this->escapeIcsString($event['description']) . "\r\n";
        }
        $ics .= "STATUS:" . $event['status'] . "\r\n";
        $ics .= ($dtStartFormat === 'Ymd') ? "TRANSP:TRANSPARENT\r\n" : "TRANSP:OPAQUE\r\n";
        $ics .= "END:VEVENT\r\n";

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    private function escapeIcsString(?string $string): string {
        if ($string === null) return '';
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(';', '\;', $string);
        $string = str_replace(',', '\,', $string);
        $string = str_replace("\r", '', $string);
        $string = str_replace("\n", '\n', $string);
        return $string;
    }
}