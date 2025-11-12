<?php
namespace App\Services;
use App\Repositories\UserRepository;
use App\Repositories\PlanRepository;
use App\Repositories\AcademicEventRepository;
use App\Repositories\AppointmentRepository; // NEU
use App\Core\Utils;
use PDO;
use Exception;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;

class IcalService
{
    private UserRepository $userRepository;
    private PlanRepository $planRepository;
    private AcademicEventRepository $eventRepository;
    private AppointmentRepository $appointmentRepository; // NEU
    private array $settings;
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

    public function __construct(
        UserRepository $userRepository,
        PlanRepository $planRepository,
        AcademicEventRepository $eventRepository,
        AppointmentRepository $appointmentRepository, // NEU
        array $settings
    ) {
        $this->userRepository = $userRepository;
        $this->planRepository = $planRepository;
        $this->eventRepository = $eventRepository;
        $this->appointmentRepository = $appointmentRepository; // NEU
        $this->settings = $settings;
    }

    public function generateFeed(string $token): string
    {
        if (empty($this->settings['ical_enabled'])) {
            throw new Exception("Kalender-Feeds sind derzeit systemweit deaktiviert.", 503);
        }

        $user = $this->userRepository->findByIcalToken($token);
        if (!$user) {
            throw new Exception("Ungültiger oder unbekannter Kalender-Feed-Token.", 404);
        }

        $userRole = $user['role'];
        $userId = (int)$user['user_id']; // NEU: user_id holen
        $classId = ($userRole === 'schueler' && !empty($user['class_id'])) ? (int)$user['class_id'] : null;
        $teacherId = ($userRole === 'lehrer' && !empty($user['teacher_id'])) ? (int)$user['teacher_id'] : null;
        $teacherUserId = ($userRole === 'lehrer') ? (int)$user['user_id'] : null; // $teacherUserId ist $userId

        if (($userRole !== 'schueler' && $userRole !== 'lehrer') || ($userRole === 'schueler' && !$classId) || ($userRole === 'lehrer' && !$teacherId)) {
            throw new Exception("Kalender-Feed nur für gültige Schüler- oder Lehrerprofile verfügbar.", 403);
        }

        $timezone = new DateTimeZone('Europe/Berlin');
        $now = new DateTimeImmutable('now', $timezone);
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
                $academicEventsForWeek = [];
                $appointmentsForWeek = []; // NEU
                
                // Datumsbereich für Abfragen (Mo-So der Zielwoche)
                [$startDate, $endDate] = $this->getWeekDateRange($targetYear, $targetWeek); 

                if ($this->planRepository->isWeekPublishedFor($targetGroup, $targetYear, $targetWeek)) {
                    $timetable = [];
                    $substitutions = [];
                    
                    if ($classId) { // Schüler
                        $timetable = $this->planRepository->getPublishedTimetableForClass($classId, $targetYear, $targetWeek);
                        $substitutions = $this->planRepository->getPublishedSubstitutionsForClassWeek($classId, $targetYear, $targetWeek);
                        $academicEventsForWeek = $this->eventRepository->getEventsForClassByWeek($classId, $targetYear, $targetWeek);
                        // NEU: Termine für Schüler abrufen
                        $appointmentsForWeek = $this->appointmentRepository->getAppointmentsForStudent($userId, $startDate, $endDate); 
                    } elseif ($teacherId && $teacherUserId) { // Lehrer
                        $timetable = $this->planRepository->getPublishedTimetableForTeacher($teacherId, $targetYear, $targetWeek);
                        $substitutions = $this->planRepository->getPublishedSubstitutionsForTeacherWeek($teacherId, $targetYear, $targetWeek);
                        $academicEventsForWeek = $this->eventRepository->getEventsByTeacherForDateRange($teacherUserId, $startDate, $endDate);
                        // NEU: Termine für Lehrer abrufen
                        $appointmentsForWeek = $this->appointmentRepository->getAppointmentsForTeacher($userId, $startDate, $endDate); 
                    }
                    
                    $this->processWeekData($allEventsData, $timetable, $substitutions, $targetYear, $targetWeek, $timezone, $userRole);
                    $this->processAcademicEvents($allEventsData, $academicEventsForWeek, $timezone);
                    $this->processAppointments($allEventsData, $appointmentsForWeek, $timezone, $userRole); // NEU
                }
            } 
        } catch (Exception $e) {
            error_log("iCal feed generation error for token {$token}: " . $e->getMessage());
            throw new Exception("Fehler beim Abrufen der Kalenderdaten.", 500);
        }

        return $this->formatAsIcs($allEventsData, $user);
    }

    private function getWeekYear(DateTimeImmutable $date): array
    {
        $year = (int)$date->format('o'); 
        $week = (int)$date->format('W'); 
        return ['week' => $week, 'year' => $year];
    }

    private function getWeekDateRange(int $year, int $week): array
    {
        $dto = new DateTime();
        $dto->setISODate($year, $week, 1); 
        $startDate = $dto->format('Y-m-d');
        $dto->setISODate($year, $week, 7); // Bis Sonntag
        $endDate = $dto->format('Y-m-d');
        return [$startDate, $endDate];
    }

    private function getDateForDayOfWeek(int $year, int $week, int $dayNum, DateTimeZone $timezone): DateTimeImmutable
    {
        $dt = new DateTimeImmutable("{$year}-W" . sprintf('%02d', $week) . "-{$dayNum}", $timezone);
        return $dt->setTime(0, 0, 0);
    }

    private function processWeekData(array &$events, array $timetable, array $substitutions, int $year, int $week, DateTimeZone $timezone, string $userRole): void
    {
        $processedRegularSlots = [];
        $substitutionMap = [];
        foreach ($substitutions as $sub) {
            $key = $sub['date'] . '-' . $sub['period_number'];
            $substitutionMap[$key] = $sub;
        }

        // Vertretungen zuerst verarbeiten
        foreach ($substitutions as $sub) {
            $subKey = $sub['date'] . '-' . $sub['period_number'];
            if (isset($processedRegularSlots[$subKey])) continue; // Bereits als Teil eines Blocks verarbeitet

            $period = (int)$sub['period_number'];
            $times = self::PERIOD_TIMES[$period] ?? null;
            if (!$times) continue;

            try {
                $dateObj = new DateTimeImmutable($sub['date'] . ' 00:00:00', $timezone);
            } catch (Exception $e) {
                error_log("Invalid date format in substitution: " . $sub['date']);
                continue;
            }
            
            $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
            $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));

            // Blockbildung für Vertretungen
            $span = 1;
            while (true) {
                $nextPeriod = $period + $span;
                $nextSubKey = $sub['date'] . '-' . $nextPeriod;
                if (isset(self::PERIOD_TIMES[$nextPeriod]) && isset($substitutionMap[$nextSubKey])) {
                    $nextSub = $substitutionMap[$nextSubKey];
                    // Prüfen, ob die nächste Stunde der gleiche Vertretungstyp ist
                    if ($nextSub['substitution_type'] === $sub['substitution_type'] &&
                        $nextSub['comment'] === $sub['comment'] &&
                        $nextSub['new_teacher_id'] === $sub['new_teacher_id'] &&
                        $nextSub['new_subject_id'] === $sub['new_subject_id'] &&
                        $nextSub['new_room_id'] === $sub['new_room_id']) 
                    {
                        $nextTimes = self::PERIOD_TIMES[$nextPeriod];
                        $dtEnd = $dateObj->setTime((int)substr($nextTimes['end'], 0, 2), (int)substr($nextTimes['end'], 2, 2));
                        $processedRegularSlots[$nextSubKey] = true;
                        $span++;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }

            $originalEntry = null;
            if ($sub['substitution_type'] !== 'Sonderevent') {
                 $dbDayNum = $dateObj->format('N');
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
                    $location = ''; 
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
                'uid' => 'sub-' . $sub['substitution_id'],
                'dtStart' => $dtStart,
                'dtEnd' => $dtEnd,
                'summary' => $summary,
                'location' => $location,
                'description' => trim($description),
                'status' => $status,
            ];

             $processedRegularSlots[$subKey] = true;
        }

        // Reguläre Stunden verarbeiten
        foreach ($timetable as $entry) {
            $dayNum = (int)$entry['day_of_week'];
            $period = (int)$entry['period_number'];
            $regKey = $this->getDateForDayOfWeek($year, $week, $dayNum, $timezone)->format('Y-m-d') . '-' . $period;
            $blockId = $entry['block_id'];
            
            // Überspringen, wenn von Vertretung betroffen oder Teil eines bereits verarbeiteten Blocks
            if (isset($substitutionMap[$regKey]) || isset($processedRegularSlots[$regKey]) || ($blockId && isset($processedRegularSlots[$blockId]))) {
                continue;
            }

            $times = self::PERIOD_TIMES[$period] ?? null;
            if (!$times) continue;

            $dateObj = $this->getDateForDayOfWeek($year, $week, $dayNum, $timezone);
            $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
            $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));
            
            $uidBase = 'entry-' . $entry['entry_id'];

            if ($blockId) {
                // Dies ist der Start eines Blocks, der noch nicht verarbeitet wurde
                $blockEntries = array_filter($timetable, fn($e) => $e['block_id'] === $blockId);
                if (!empty($blockEntries)) {
                    $minPeriod = min(array_column($blockEntries, 'period_number'));
                    $maxPeriod = max(array_column($blockEntries, 'period_number'));
                    
                    if ($period === $minPeriod) { // Nur den ersten Eintrag des Blocks verarbeiten
                         $startTime = self::PERIOD_TIMES[$minPeriod]['start'] ?? null;
                         $endTime = self::PERIOD_TIMES[$maxPeriod]['end'] ?? null;
                         if ($startTime && $endTime) {
                             $dtStart = $dateObj->setTime((int)substr($startTime, 0, 2), (int)substr($startTime, 2, 2));
                             $dtEnd = $dateObj->setTime((int)substr($endTime, 0, 2), (int)substr($endTime, 2, 2));
                         }
                         $uidBase = 'block-' . $blockId;
                         // Alle Slots dieses Blocks als verarbeitet markieren
                         for ($p = $minPeriod; $p <= $maxPeriod; $p++) {
                             $blockKey = $dateObj->format('Y-m-d') . '-' . $p;
                             $processedRegularSlots[$blockKey] = true;
                         }
                         $processedRegularSlots[$blockId] = true; // Block-ID selbst markieren
                    } else {
                         continue; // Nicht der Start-Eintrag des Blocks
                    }
                } else {
                     $processedRegularSlots[$regKey] = true; // Als Einzelstunde markieren
                }
            } else {
                $processedRegularSlots[$regKey] = true; // Als Einzelstunde markieren
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
        }
    }

    private function processAcademicEvents(array &$events, array $academicEvents, DateTimeZone $timezone): void
    {
        foreach ($academicEvents as $event) {
            try {
                $dateObj = new DateTimeImmutable($event['due_date'] . ' 00:00:00', $timezone);
            } catch (Exception $e) {
                error_log("Invalid date format in academic event: " . $event['due_date']);
                continue;
            }

            $times = null; 

            if ($times) {
                // Logik für zeitbasierte Events (derzeit nicht verwendet)
                $dtStart = $dateObj->setTime((int)substr($times['start'], 0, 2), (int)substr($times['start'], 2, 2));
                $dtEnd = $dateObj->setTime((int)substr($times['end'], 0, 2), (int)substr($times['end'], 2, 2));
                $dtStartFormat = 'Ymd\THis';
                $dtEndFormat = 'Ymd\THis';
                $timeInfo = ""; 
            } else {
                // Ganztägiges Event
                $dtStart = $dateObj;
                $dtEnd = $dateObj->modify('+1 day');
                $dtStartFormat = 'Ymd';
                $dtEndFormat = 'Ymd';
                $timeInfo = "";
            }

            $icon = 'ℹ️'; $prefix = 'Info';
            if ($event['event_type'] === 'klausur') { $icon = '🎓'; $prefix = 'Klausur'; }
            if ($event['event_type'] === 'aufgabe') { $icon = '📚'; $prefix = 'Aufgabe'; }

            $summary = "{$icon} {$prefix}: " . ($event['title'] ?? 'Eintrag');
            if ($event['subject_shortcut']) {
                $summary .= " (" . $event['subject_shortcut'] . ")";
            }

            $description = "Typ: " . ucfirst($event['event_type']) . "\n";
            $description .= "Fach: " . ($event['subject_shortcut'] ?? '-') . "\n";
            if (isset($event['teacher_first_name'])) {
                 $description .= "Lehrer: " . $event['teacher_first_name'] . ' ' . $event['teacher_last_name'] . "\n";
            }
            if (isset($event['class_name'])) {
                 $description .= "Klasse: " . $event['class_name'] . "\n";
            }
            $description .= "Datum: " . $dateObj->format('d.m.Y') . $timeInfo . "\n";
            if ($event['description']) {
                $description .= "\nBeschreibung:\n" . $event['description'];
            }

            $events[] = [
                'uid' => 'acad-' . $event['event_id'],
                'dtStart' => $dtStart,
                'dtEnd' => $dtEnd,
                'dtStartFormat' => $dtStartFormat,
                'dtEndFormat' => $dtEndFormat,
                'summary' => $summary,
                'location' => '',
                'description' => trim($description),
                'status' => 'CONFIRMED',
            ];
        }
    }

    // NEU: Verarbeitet gebuchte Sprechstunden
    private function processAppointments(array &$events, array $appointments, DateTimeZone $timezone, string $userRole): void
    {
        foreach ($appointments as $app) {
            try {
                // Termin-Zeit berechnen
                $dateObj = new DateTimeImmutable($app['appointment_date'] . ' ' . $app['appointment_time'], $timezone);
                $dtStart = $dateObj;
                $dtEnd = $dateObj->modify('+' . intval($app['duration'] ?? 15) . ' minutes');
                $dtStartFormat = 'Ymd\THis';
                $dtEndFormat = 'Ymd\THis';

                $summary = '🗣️ Sprechstunde';
                $description = "Sprechstunde\n";
                $location = $app['location'] ?? 'N/A';

                if ($userRole === 'schueler') {
                    $summary .= ' bei ' . ($app['teacher_shortcut'] ?? $app['teacher_name'] ?? 'Lehrer');
                    $description .= "Lehrer: " . ($app['teacher_name'] ?? 'N/A') . "\n";
                    if (!empty($app['notes'])) {
                        $description .= "Deine Notiz: " . $app['notes'] . "\n";
                    }
                } else { // lehrer
                    $summary .= ' mit ' . ($app['student_name'] ?? 'Schüler');
                    $description .= "Schüler: " . ($app['student_name'] ?? 'N/A') . "\n";
                    if (!empty($app['class_name'])) {
                        $description .= "Klasse: " . $app['class_name'] . "\n";
                    }
                    if (!empty($app['notes'])) {
                        $description .= "Notiz des Schülers: " . $app['notes'] . "\n";
                    }
                }
                $description .= "Ort: " . $location . "\n";
                $description .= "Status: Gebucht";

                $events[] = [
                    'uid' => 'appt-' . $app['appointment_id'],
                    'dtStart' => $dtStart,
                    'dtEnd' => $dtEnd,
                    'dtStartFormat' => $dtStartFormat,
                    'dtEndFormat' => $dtEndFormat,
                    'summary' => $summary,
                    'location' => $location,
                    'description' => trim($description),
                    'status' => 'CONFIRMED',
                ];
            } catch (Exception $e) {
                error_log("Fehler beim Verarbeiten von Termin (ID: {$app['appointment_id']}): " . $e->getMessage());
            }
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
        $ics .= "X-PUBLISHED-TTL:PT1H\r\n"; // 1 Stunde Cache

        // VTIMEZONE Definition für Europe/Berlin
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

        foreach ($events as $event) {
            $dtStart = $event['dtStart'];
            $dtEnd = $event['dtEnd'];
            
            $dtStartFormat = $event['dtStartFormat'] ?? 'Ymd\THis';
            $dtEndFormat = $event['dtEndFormat'] ?? 'Ymd\THis';

            $dtStartString = $dtStart->format($dtStartFormat);
            $dtEndString = $dtEnd->format($dtEndFormat);

            $datePrefix = ($dtStartFormat === 'Ymd') ? ';VALUE=DATE' : ';TZID=Europe/Berlin';

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
        }

        $ics .= "END:VCALENDAR\r\n";
        return $ics;
    }

    private function escapeIcsString(?string $string): string
    {
        if ($string === null) return '';
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(';', '\;', $string);
        $string = str_replace(',', '\,', $string);
        $string = str_replace("\r", '', $string); 
        $string = str_replace("\n", '\n', $string); 
        return $string;
    }
}