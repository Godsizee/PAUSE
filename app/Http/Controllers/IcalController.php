<?php
// app/Http\Controllers\IcalController.php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Core\Utils;
use App\Repositories\UserRepository;
use App\Repositories\PlanRepository;
use App\Repositories\AcademicEventRepository; // NEU: Importiere Event Repository
use DateTime;
use DateTimeImmutable; // Use immutable for calculations
use DateTimeZone;
use Exception;

class IcalController
{
    private UserRepository $userRepository;
    private PlanRepository $planRepository;
    private AcademicEventRepository $eventRepository; // NEU: Property für Event Repository
    private \PDO $pdo;
    private array $settings;

    // Define exact start and end times for each period (HHMM format for calculations)
    // Adjust these times according to your actual school schedule!
    private const PERIOD_TIMES = [
        1 => ['start' => '0800', 'end' => '0845'],
        2 => ['start' => '0855', 'end' => '0940'],
        3 => ['start' => '0940', 'end' => '1025'], // Example: Double period
        4 => ['start' => '1035', 'end' => '1120'],
        5 => ['start' => '1120', 'end' => '1205'],
        // Mittagspause
        6 => ['start' => '1305', 'end' => '1350'],
        7 => ['start' => '1350', 'end' => '1435'],
        8 => ['start' => '1445', 'end' => '1530'],
        9 => ['start' => '1530', 'end' => '1615'],
        10 => ['start' => '1625', 'end' => '1710'],
    ];

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->userRepository = new UserRepository($this->pdo);
        $this->planRepository = new PlanRepository($this->pdo);
        $this->eventRepository = new AcademicEventRepository($this->pdo); // NEU: Instanziiere Event Repository
        $this->settings = Utils::getSettings();
    }

    public function generateFeed(string $token)
    {
        // Schritt 0: Prüfen, ob iCal global aktiviert ist
        if (!$this->settings['ical_enabled']) {
            http_response_code(503); // Service Unavailable
            header('Content-Type: text/plain');
            echo "Kalender-Feeds sind derzeit systemweit deaktiviert.";
            exit;
        }

        // 1. Validate Token & Find User
        $user = $this->userRepository->findByIcalToken($token);
        if (!$user) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Ungültiger oder unbekannter Kalender-Feed-Token.";
            exit;
        }

        // 2. Determine User Type
        $userRole = $user['role'];
        $classId = ($userRole === 'schueler' && !empty($user['class_id'])) ? (int)$user['class_id'] : null;
        $teacherId = ($userRole === 'lehrer' && !empty($user['teacher_id'])) ? (int)$user['teacher_id'] : null;
        $teacherUserId = ($userRole === 'lehrer') ? (int)$user['user_id'] : null; // NEU: Lehrer User ID für Events

        if (($userRole !== 'schueler' && $userRole !== 'lehrer') || ($userRole === 'schueler' && !$classId) || ($userRole === 'lehrer' && !$teacherId)) {
            http_response_code(403);
            header('Content-Type: text/plain');
            echo "Kalender-Feed nur für gültige Schüler- oder Lehrerprofile verfügbar.";
            exit;
        }

        // 3. Fetch Data
        $timezone = new DateTimeZone('Europe/Berlin');
        $now = new DateTimeImmutable('now', $timezone); // Use Immutable
        $currentWeekInfo = $this->getWeekYear($now);
        $currentYear = $currentWeekInfo['year'];
        $currentWeek = $currentWeekInfo['week'];

        $allEventsData = [];
        $rangeWeeksBefore = 1;
        $rangeWeeksAfter = $this->settings['ical_weeks_future'];

        try {
            for ($weekOffset = -$rangeWeeksBefore; $weekOffset <= $rangeWeeksAfter; $weekOffset++) {
                $dt = $now->modify('+' . ($weekOffset * 7) . ' days');
                $weekInfo = $this->getWeekYear($dt);
                $targetYear = $weekInfo['year'];
                $targetWeek = $weekInfo['week'];

                $targetGroup = ($userRole === 'schueler') ? 'student' : 'teacher';

                $academicEventsForWeek = []; // NEU: Initialisiere Events-Array für die Woche

                if ($this->planRepository->isWeekPublishedFor($targetGroup, $targetYear, $targetWeek)) {
                    $timetable = [];
                    $substitutions = [];
                    if ($classId) {
                        $timetable = $this->planRepository->getPublishedTimetableForClass($classId, $targetYear, $targetWeek);
                        $substitutions = $this->planRepository->getPublishedSubstitutionsForClassWeek($classId, $targetYear, $targetWeek);
                        // NEU: Lade Events für Schüler
                        $academicEventsForWeek = $this->eventRepository->getEventsForClassByWeek($classId, $targetYear, $targetWeek);
                    } elseif ($teacherId && $teacherUserId) { // Stelle sicher, dass teacherUserId vorhanden ist
                        $timetable = $this->planRepository->getPublishedTimetableForTeacher($teacherId, $targetYear, $targetWeek);
                        $substitutions = $this->planRepository->getPublishedSubstitutionsForTeacherWeek($teacherId, $targetYear, $targetWeek);
                        // NEU: Lade Events für Lehrer (die er erstellt hat) - braucht Start/End Datum
                        [$startDate, $endDate] = $this->getWeekDateRange($targetYear, $targetWeek);
                        // Hole Events für den Lehrer im gesamten Wochenbereich
                        $academicEventsForWeek = $this->eventRepository->getEventsByTeacherForDateRange($teacherUserId, $startDate, $endDate);
                    }

                    $this->processWeekData($allEventsData, $timetable, $substitutions, $targetYear, $targetWeek, $timezone, $userRole);

                    // NEU: Verarbeite Academic Events für diese Woche
                    $this->processAcademicEvents($allEventsData, $academicEventsForWeek, $timezone);
                }
            } // End week loop

        } catch (Exception $e) {
            error_log("iCal feed generation error for token {$token}: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Fehler beim Abrufen der Kalenderdaten.";
            exit;
        }


        // 4. Format as iCalendar
        $icsContent = $this->formatAsIcs($allEventsData, $user);

        // 5. Output Headers and Content
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="pause_stundenplan.ics"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $icsContent;
        exit;
    }

    private function getWeekYear(DateTimeImmutable $date): array {
        $year = (int)$date->format('o'); // ISO-8601 year
        $week = (int)$date->format('W'); // ISO-8601 week number
        return ['week' => $week, 'year' => $year];
    }

    /**
    * Hilfsfunktion, um Start- und Enddatum einer Kalenderwoche zu ermitteln (Mo - So).
    * @param int $year ISO Year
    * @param int $week ISO Week
    * @return array ['Y-m-d', 'Y-m-d']
    */
    private function getWeekDateRange(int $year, int $week): array
    {
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1); // Montag
        $startDate = $dto->format('Y-m-d');
        $dto->setISODate($year, $week, 7); // Sonntag
        $endDate = $dto->format('Y-m-d');
        return [$startDate, $endDate];
    }


    /**
     * Gets the DateTimeImmutable object for the start of a given day (00:00:00) within a specific week/year.
     * @param int $year ISO Year
     * @param int $week ISO Week
     * @param int $dayNum Day number (1=Monday, ..., 7=Sunday)
     * @param DateTimeZone $timezone
     * @return DateTimeImmutable
     */
    private function getDateForDayOfWeek(int $year, int $week, int $dayNum, DateTimeZone $timezone): DateTimeImmutable {
        $dt = new DateTimeImmutable("{$year}-W" . sprintf('%02d', $week) . "-{$dayNum}", $timezone);
        return $dt->setTime(0, 0, 0); // Ensure start of the day
    }


    private function processWeekData(array &$events, array $timetable, array $substitutions, int $year, int $week, DateTimeZone $timezone, string $userRole): void
    {
        $processedRegularSlots = []; // Keep track of processed regular slots 'day-period' or block_id
        $substitutionMap = []; // Map substitutions to 'YYYY-MM-DD-period'
        foreach ($substitutions as $sub) {
            $key = $sub['date'] . '-' . $sub['period_number'];
            $substitutionMap[$key] = $sub;
        }

        // --- Process Substitutions First ---
        foreach ($substitutions as $sub) {
            $subKey = $sub['date'] . '-' . $sub['period_number'];
            if (isset($processedRegularSlots[$subKey])) continue; // Already processed as part of a block

            $period = (int)$sub['period_number'];
            $times = self::PERIOD_TIMES[$period] ?? null;
            if (!$times) continue; // Skip if period time is undefined

            // Use DateTimeImmutable for safety
            try {
                $dateObj = new DateTimeImmutable($sub['date'] . ' 00:00:00', $timezone);
            } catch (Exception $e) {
                error_log("Invalid date format in substitution: " . $sub['date']);
                continue;
            }

            $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
            $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));

            // Check for multi-period substitutions (simple consecutive check)
            $span = 1;
            while (true) {
                $nextPeriod = $period + $span;
                $nextSubKey = $sub['date'] . '-' . $nextPeriod;
                 // Check if next period exists, has a substitution, and if it's essentially the same event
                 // (same type, comment, new teacher/subject/room)
                if (isset(self::PERIOD_TIMES[$nextPeriod]) && isset($substitutionMap[$nextSubKey])) {
                    $nextSub = $substitutionMap[$nextSubKey];
                    if ($nextSub['substitution_type'] === $sub['substitution_type'] &&
                        $nextSub['comment'] === $sub['comment'] &&
                        $nextSub['new_teacher_id'] === $sub['new_teacher_id'] &&
                        $nextSub['new_subject_id'] === $sub['new_subject_id'] &&
                        $nextSub['new_room_id'] === $sub['new_room_id'])
                    {
                         $nextTimes = self::PERIOD_TIMES[$nextPeriod];
                         $dtEnd = $dateObj->setTime((int)substr($nextTimes['end'], 0, 2), (int)substr($nextTimes['end'], 2, 2));
                         $processedRegularSlots[$nextSubKey] = true; // Mark as processed
                         $span++;
                    } else {
                         break; // Different substitution, stop block
                    }
                } else {
                     break; // No more consecutive substitutions
                }
            }

            // Find original entry for context if substitution isn't 'Sonderevent'
            $originalEntry = null;
            if ($sub['substitution_type'] !== 'Sonderevent') {
                 // Try finding matching regular entry for the same day/period
                 $dbDayNum = $dateObj->format('N'); // 1 (Mon) - 7 (Sun)
                 $originalEntry = array_values(array_filter($timetable,
                    fn($e) => $e['day_of_week'] == $dbDayNum && $e['period_number'] == $period
                 ))[0] ?? null;
            }

            $summary = '';
            $description = "Typ: " . $sub['substitution_type'] . "\n";
            $location = $sub['new_room_name'] ?? $originalEntry['room_name'] ?? '';
            $status = 'CONFIRMED';

            switch ($sub['substitution_type']) {
                case 'Entfall':
                    $summary = "ENTFALL: " . ($originalEntry['subject_shortcut'] ?? 'Unterricht');
                    $description .= "Ursprünglich: " . ($originalEntry['subject_shortcut'] ?? '?') . " bei " . ($originalEntry['teacher_shortcut'] ?? '?') . "\n";
                    $location = ''; // No location for cancellation
                    $status = 'CANCELLED';
                    break;
                case 'Raumänderung':
                    $summary = ($originalEntry['subject_shortcut'] ?? 'Unterricht') . " in Raum " . ($sub['new_room_name'] ?? '???');
                    $description .= "Neuer Raum: " . ($sub['new_room_name'] ?? '???') . "\n";
                    $description .= "Fach: " . ($originalEntry['subject_shortcut'] ?? '?') . "\n";
                    $description .= "Lehrer/Klasse: " . ($userRole === 'schueler' ? ($originalEntry['teacher_shortcut'] ?? '?') : ($originalEntry['class_name'] ?? '?')) . "\n";
                    break;
                case 'Sonderevent':
                    $summary = $sub['comment'] ?: 'Sonderevent';
                    $location = $sub['new_room_name'] ?? '';
                    break;
                case 'Vertretung':
                default:
                    $subject = $sub['new_subject_shortcut'] ?? $originalEntry['subject_shortcut'] ?? '???';
                    $teacher = $sub['new_teacher_shortcut'] ?? '???';
                    $class = $sub['class_name'] ?? $originalEntry['class_name'] ?? '???';
                    $summary = "VERTR.: {$subject} - " . ($userRole === 'schueler' ? $teacher : $class);
                    $description .= "Fach: {$subject}\n";
                    $description .= "Lehrer: {$teacher}\n";
                    $description .= "Raum: " . ($sub['new_room_name'] ?? $originalEntry['room_name'] ?? '???') . "\n";
                    if ($originalEntry) {
                         $description .= "Ursprünglich: " . $originalEntry['subject_shortcut'] . " bei " . $originalEntry['teacher_shortcut'] . "\n";
                    }
                    break;
            }
            if ($sub['comment'] && $sub['substitution_type'] !== 'Sonderevent') {
                $description .= "Kommentar: " . $sub['comment'] . "\n";
            }

            $events[] = [
                'uid' => 'sub-' . $sub['substitution_id'], // Unique ID based on substitution
                'dtStart' => $dtStart,
                'dtEnd' => $dtEnd,
                'summary' => $summary,
                'location' => $location,
                'description' => trim($description),
                'status' => $status,
            ];

             $processedRegularSlots[$subKey] = true; // Mark the starting slot
        } // End substitution processing


        // --- Process Regular Timetable Entries (excluding overridden slots) ---
        foreach ($timetable as $entry) {
            $dayNum = (int)$entry['day_of_week'];
            $period = (int)$entry['period_number'];
            $regKey = $this->getDateForDayOfWeek($year, $week, $dayNum, $timezone)->format('Y-m-d') . '-' . $period;
            $blockId = $entry['block_id'];

            // Skip if already handled by substitution OR already processed as part of a block
            if (isset($substitutionMap[$regKey]) || isset($processedRegularSlots[$regKey]) || ($blockId && isset($processedRegularSlots[$blockId]))) {
                continue;
            }

            $times = self::PERIOD_TIMES[$period] ?? null;
            if (!$times) continue;

            $dateObj = $this->getDateForDayOfWeek($year, $week, $dayNum, $timezone);
            $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
            $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));
            $uidBase = 'entry-' . $entry['entry_id'];

            // Handle Blocks
            if ($blockId) {
                // Find all entries for this block
                $blockEntries = array_filter($timetable, fn($e) => $e['block_id'] === $blockId);
                if (!empty($blockEntries)) {
                    $minPeriod = min(array_column($blockEntries, 'period_number'));
                    $maxPeriod = max(array_column($blockEntries, 'period_number'));

                    // Only process the block once using the first period entry
                    if ($period === $minPeriod) {
                         $startTime = self::PERIOD_TIMES[$minPeriod]['start'] ?? null;
                         $endTime = self::PERIOD_TIMES[$maxPeriod]['end'] ?? null;
                         if ($startTime && $endTime) {
                             $dtStart = $dateObj->setTime((int)substr($startTime, 0, 2), (int)substr($startTime, 2, 2));
                             $dtEnd = $dateObj->setTime((int)substr($endTime, 0, 2), (int)substr($endTime, 2, 2));
                         }
                         $uidBase = 'block-' . $blockId;
                         // Mark all periods of this block as processed
                         for ($p = $minPeriod; $p <= $maxPeriod; $p++) {
                             $blockKey = $dateObj->format('Y-m-d') . '-' . $p;
                             $processedRegularSlots[$blockKey] = true;
                         }
                         $processedRegularSlots[$blockId] = true; // Mark block itself
                    } else {
                         continue; // Skip other entries of an already processed block
                    }
                }
            } else {
                 $processedRegularSlots[$regKey] = true; // Mark single entry
            }


            $summary = ($entry['subject_shortcut'] ?? '???') . " - " . ($userRole === 'schueler' ? ($entry['teacher_shortcut'] ?? '???') : ($entry['class_name'] ?? '???'));
            $description = "Fach: " . ($entry['subject_name'] ?? $entry['subject_shortcut'] ?? '???') . "\n";
            $description .= "Lehrer: " . ($entry['teacher_shortcut'] ?? '???') . "\n";
            $description .= "Klasse: " . ($entry['class_name'] ?? '???') . "\n";
            $description .= "Raum: " . ($entry['room_name'] ?? '???') . "\n";
            if (!empty($entry['comment'])) {
                $description .= "Kommentar: " . $entry['comment'] . "\n";
            }

            $events[] = [
                'uid' => $uidBase,
                'dtStart' => $dtStart,
                'dtEnd' => $dtEnd,
                'summary' => $summary,
                'location' => $entry['room_name'] ?? '',
                'description' => trim($description),
                'status' => 'CONFIRMED',
            ];

        } // End regular entry processing
    }

    /**
     * NEU: Verarbeitet Aufgaben/Klausuren und fügt sie zur Event-Liste hinzu.
     * @param array &$events Das Haupt-Event-Array (wird modifiziert).
     * @param array $academicEvents Die zu verarbeitenden Aufgaben/Klausuren.
     * @param DateTimeZone $timezone Die Zeitzone.
     */
    private function processAcademicEvents(array &$events, array $academicEvents, DateTimeZone $timezone): void
    {
        foreach ($academicEvents as $event) {
            try {
                $dateObj = new DateTimeImmutable($event['due_date'] . ' 00:00:00', $timezone);
            } catch (Exception $e) {
                error_log("Invalid date format in academic event: " . $event['due_date']);
                continue;
            }

            $period = $event['period_number'] ? (int)$event['period_number'] : null;
            $times = $period ? (self::PERIOD_TIMES[$period] ?? null) : null;

            if ($times) {
                // Event findet zu einer bestimmten Stunde statt
                $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
                $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));
                // iCal Format für spezifische Zeit (kein DATE)
                $dtStartFormat = 'Ymd\THis';
                $dtEndFormat = 'Ymd\THis';
                $timeInfo = " ({$period}. Std.)";
            } else {
                // Ganztägiges Event (oder ohne spezifische Zeit)
                $dtStart = $dateObj; // Start of the day
                $dtEnd = $dateObj->modify('+1 day'); // End of the day (exclusive in iCal)
                // iCal Format für ganztägige Events (DATE)
                $dtStartFormat = 'Ymd';
                $dtEndFormat = 'Ymd';
                $timeInfo = "";
            }

            $icon = 'ℹ️'; // Info
            $prefix = 'Info';
            if ($event['event_type'] === 'klausur') {
                $icon = '🎓';
                $prefix = 'Klausur';
            }
            if ($event['event_type'] === 'aufgabe') {
                $icon = '📚';
                $prefix = 'Aufgabe';
            }

            $summary = "{$icon} {$prefix}: " . ($event['title'] ?? 'Eintrag');
            if ($event['subject_shortcut']) {
                $summary .= " (" . $event['subject_shortcut'] . ")";
            }

            $description = "Typ: " . ucfirst($event['event_type']) . "\n";
            $description .= "Fach: " . ($event['subject_shortcut'] ?? '-') . "\n";
            // Lehrername hinzufügen (falls verfügbar, z.B. für Schüleransicht)
            if (isset($event['teacher_first_name'])) {
                 $description .= "Lehrer: " . $event['teacher_first_name'] . ' ' . $event['teacher_last_name'] . "\n";
            }
            // Klassenname hinzufügen (falls verfügbar, z.B. für Lehreransicht)
            if (isset($event['class_name'])) {
                 $description .= "Klasse: " . $event['class_name'] . "\n";
            }

            $description .= "Datum: " . $dateObj->format('d.m.Y') . $timeInfo . "\n";
            if ($event['description']) {
                $description .= "\nBeschreibung:\n" . $event['description'];
            }

            $events[] = [
                'uid' => 'acad-' . $event['event_id'], // Eindeutige ID
                'dtStart' => $dtStart,
                'dtEnd' => $dtEnd,
                'dtStartFormat' => $dtStartFormat, // NEU: Format für Start
                'dtEndFormat' => $dtEndFormat,     // NEU: Format für Ende
                'summary' => $summary,
                'location' => '', // Kein spezifischer Ort für Aufgaben/Klausuren im iCal
                'description' => trim($description),
                'status' => 'CONFIRMED',
            ];
        }
    }


    private function formatAsIcs(array $events, array $user): string
    {
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//PMI//PAUSE Stundenplan v1.0//DE\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:PUBLISH\r\n";
        $ics .= "X-WR-CALNAME:PAUSE Stundenplan (" . $this->escapeIcsString($user['username']) . ")\r\n";
        $ics .= "X-WR-TIMEZONE:Europe/Berlin\r\n";
        $ics .= "X-PUBLISHED-TTL:PT1H\r\n";

        // Add Timezone Definition
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

        // Loop through processed events and create VEVENT blocks
        foreach ($events as $event) {
            /** @var DateTimeImmutable $dtStart */
            /** @var DateTimeImmutable $dtEnd */
            $dtStart = $event['dtStart'];
            $dtEnd = $event['dtEnd'];

            // NEU: Verwende spezifische Formate für ganztägige vs. zeitgebundene Events
            $dtStartFormat = $event['dtStartFormat'] ?? 'Ymd\THis';
            $dtEndFormat = $event['dtEndFormat'] ?? 'Ymd\THis';
            $dtStartString = $dtStart->format($dtStartFormat);
            $dtEndString = $dtEnd->format($dtEndFormat);
            $datePrefix = ($dtStartFormat === 'Ymd') ? ';VALUE=DATE' : ';TZID=Europe/Berlin';


            $ics .= "BEGIN:VEVENT\r\n";
            // UID: Use base + start time to ensure uniqueness even if details change slightly
            $ics .= "UID:" . $event['uid'] . '-' . $dtStart->format('YmdHis') . "@pause.pmi\r\n";
            $ics .= "DTSTAMP:" . $nowUtc . "\r\n";
            // Use TZID or VALUE=DATE based on format
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
            // NEU: Setze TRANSPARENT für ganztägige Events (blockiert keine Zeit), OPAQUE für zeitgebundene
            $ics .= ($dtStartFormat === 'Ymd') ? "TRANSP:TRANSPARENT\r\n" : "TRANSP:OPAQUE\r\n";
            $ics .= "END:VEVENT\r\n";
        }


        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    private function escapeIcsString(?string $string): string {
        if ($string === null) return '';
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(';', '\;', $string);
        $string = str_replace(',', '\,', $string);
        $string = str_replace("\r", '', $string); // Remove CR
        $string = str_replace("\n", '\n', $string); // Escape LF
        return $string;
    }

} // End class