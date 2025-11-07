<?php
// app/Services/SingleIcalService.php

namespace App\Services;

use App\Repositories\AcademicEventRepository;
use App\Repositories\PlanRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Service-Klasse zur Kapselung der Logik für die Erstellung
 * einzelner .ics-Kalenderdateien (ausgelagert aus SingleEventController).
 */
class SingleIcalService
{
    private AcademicEventRepository $eventRepo;
    private PlanRepository $planRepo;
    private DateTimeZone $timezone;

    // Zeitdefinitionen (konsistent mit IcalService)
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

    /**
     * Konstruktor: Nimmt Abhängigkeiten entgegen.
     */
    public function __construct(
        AcademicEventRepository $eventRepo,
        PlanRepository $planRepo
    ) {
        $this->eventRepo = $eventRepo;
        $this->planRepo = $planRepo;
        $this->timezone = new DateTimeZone('Europe/Berlin');
    }

    /**
     * Hauptmethode zur Generierung des iCal-Strings für ein einzelnes Event.
     *
     * @param string $type Typ des Events ('acad' oder 'sub').
     * @param int $id Die ID des Events.
     * @return array ['content' => string, 'filename' => string]
     * @throws Exception Wenn das Event nicht gefunden, ungültig oder der Typ falsch ist.
     */
    public function generateSingleIcs(string $type, int $id): array
    {
        $eventData = null;

        if ($type === 'acad') {
            $eventData = $this->getAcademicEventData($id);
        } elseif ($type === 'sub') {
            $eventData = $this->getSubstitutionEventData($id);
        } else {
            throw new Exception("Ungültiger Event-Typ.", 400);
        }

        if (!$eventData) {
            throw new Exception("Termin nicht gefunden oder ungültig.", 404);
        }

        // Berechtigungsprüfung (könnte hier implementiert werden,
        // z.B. durch Übergabe von $userId an diese Methode)
        // ...

        $icsContent = $this->formatAsIcs($eventData);
        
        // Dateinamen generieren (bereinigt)
        $safeSummary = $eventData['summary'] ?? 'termin';
        $safeSummary = preg_replace('/[^a-zA-Z0-9_-]/', '_', $safeSummary);
        $safeSummary = substr($safeSummary, 0, 50); // Kürzen
        $filename = $safeSummary . '.ics';

        return [
            'content' => $icsContent,
            'filename' => $filename
        ];
    }

    /** Holt und formatiert Daten für Aufgaben/Klausuren */
    private function getAcademicEventData(int $id): ?array
    {
        $event = $this->eventRepo->getEventById($id);
        if (!$event) return null;

        $dateObj = new DateTimeImmutable($event['due_date'] . ' 00:00:00', $this->timezone);
        $dtStart = $dateObj;
        $dtEnd = $dateObj->modify('+1 day');
        $dtStartFormat = 'Ymd';
        $dtEndFormat = 'Ymd';
        $timeInfo = "";
        $location = "";

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
        $sub = $this->planRepo->getSubstitutionById($id);
        if (!$sub || $sub['substitution_type'] !== 'Sonderevent') {
            // Nur Sonderevents zulassen
            return null; 
        }

        $dateObj = new DateTimeImmutable($sub['date'] . ' 00:00:00', $this->timezone);
        $period = (int)$sub['period_number'];
        $times = self::PERIOD_TIMES[$period] ?? null;

        if (!$times) {
            // Sollte nicht passieren, wenn Daten konsistent sind
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

        // Zeitzonen-Definition
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
        /** @var DateTimeImmutable $dtStart */
        /** @var DateTimeImmutable $dtEnd */
        $dtStart = $event['dtStart'];
        $dtEnd = $event['dtEnd'];
        $dtStartFormat = $event['dtStartFormat'] ?? 'Ymd\THis';
        $dtEndFormat = $event['dtEndFormat'] ?? 'Ymd\THis';
        $dtStartString = $dtStart->format($dtStartFormat);
        $dtEndString = $dtEnd->format($dtEndFormat);
        $datePrefix = ($dtStartFormat === 'Ymd') ? ';VALUE=DATE' : ';TZID=Europe/Berlin';
        
        // Korrigiere Endformat bei Zeit-Events (falls Endformat fälschlich Ymd war)
        if ($dtStartFormat === 'Ymd\THis' && $dtEndFormat === 'Ymd') {
             $dtEndString = $dtEnd->format('Ymd\THis');
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

    /**
     * Bereinigt einen String für die Verwendung in iCal-Dateien.
     */
    private function escapeIcsString(?string $string): string
    {
        if ($string === null) return '';
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(';', '\;', $string);
        $string = str_replace(',', '\,', $string);
        $string = str_replace("\r", '', $string); // CR entfernen
        $string = str_replace("\n", '\n', $string); // LF escapen
        return $string;
    }
}