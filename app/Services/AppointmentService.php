<?php
namespace App\Services;
use DateTime;
use DateTimeZone;
use Exception;
class AppointmentService
{
    private $timezone;
    public function __construct($timezone = 'Europe/Berlin')
    {
        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $this->timezone = new DateTimeZone('UTC');
            error_log("Ungültige Zeitzone angegeben: $timezone. Fallback auf UTC.");
        }
    }
    public function formatAppointments(array $appointments): array
    {
        $formatted = [];
        foreach ($appointments as $app) {
            $formatted[] = $this->formatAppointment($app);
        }
        return $formatted;
    }
    public function formatAppointment(array $appointment): array
    {
        $formatted = $appointment;
        try {
            if (isset($appointment['start_time'])) {
                $startTime = new DateTime($appointment['start_time'], new DateTimeZone('UTC'));
                $startTime->setTimezone($this->timezone);
                $formatted['formatted_date'] = $this->formatDate($startTime);
                $formatted['formatted_start_time'] = $startTime->format('H:i');
                $formatted['relative_time'] = $this->getRelativeTime($startTime);
                if (isset($appointment['end_time'])) {
                    $endTime = new DateTime($appointment['end_time'], new DateTimeZone('UTC'));
                    $endTime->setTimezone($this->timezone);
                    $formatted['formatted_end_time'] = $endTime->format('H:i');
                    $formatted['formatted_duration'] = $this->formatDuration($startTime, $endTime);
                } else {
                    $formatted['formatted_end_time'] = '';
                    $formatted['formatted_duration'] = '';
                }
            } else {
                $formatted['formatted_date'] = 'Unbekannt';
                $formatted['formatted_start_time'] = '';
                $formatted['relative_time'] = 'Unbekannt';
                $formatted['formatted_end_time'] = '';
                $formatted['formatted_duration'] = '';
            }
        } catch (Exception $e) {
            $appointmentId = $appointment['appointment_id'] ?? 'unbekannt';
            error_log("Fehler bei der Formatierung des Termins (ID: {$appointmentId}): " . $e->getMessage());
            $formatted['formatted_date'] = 'Fehler';
            $formatted['formatted_start_time'] = 'Fehler';
            $formatted['relative_time'] = 'Fehler';
            $formatted['formatted_end_time'] = 'Fehler';
            $formatted['formatted_duration'] = 'Fehler';
        }
        if (isset($formatted['title'])) {
            $formatted['title'] = htmlspecialchars($formatted['title'], ENT_QUOTES, 'UTF-8');
        }
        if (isset($formatted['description'])) {
            $formatted['description'] = htmlspecialchars($formatted['description'], ENT_QUOTES, 'UTF-8');
        }
        return $formatted;
    }
    private function formatDate(DateTime $date): string
    {
        $now = new DateTime('now', $this->timezone);
        $dateOnly = (new DateTime($date->format('Y-m-d'), $this->timezone));
        $nowOnly = (new DateTime($now->format('Y-m-d'), $this->timezone));
        $diff = $nowOnly->diff($dateOnly);
        $daysDiff = (int)$diff->days;
        $isFuture = $diff->invert === 0;
        if ($daysDiff === 0) {
            return 'Heute';
        } elseif ($daysDiff === 1 && $isFuture) {
            return 'Morgen';
        } elseif ($daysDiff === 1 && !$isFuture) {
             return 'Gestern'; 
        }
        $tage = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $wochentag = $tage[$date->format('w')];
        $monate = [
            'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun',
            'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'
        ];
        $monatName = $monate[$date->format('n') - 1];
        return $wochentag . ', ' . $date->format('d.') . ' ' . $monatName;
    }
    private function getRelativeTime(DateTime $date): string
    {
        $now = new DateTime('now', $this->timezone);
        $interval = $now->diff($date);
        $isPast = $interval->invert; 
        $prefix = $isPast ? 'vor' : 'in';
        $suffix = ''; 
        if ($interval->y) {
            return $prefix . ' ' . $interval->y . ' ' . ($interval->y > 1 ? 'Jahren' : 'Jahr') . $suffix;
        }
        if ($interval->m) {
            return $prefix . ' ' . $interval->m . ' ' . ($interval->m > 1 ? 'Monaten' : 'Monat') . $suffix;
        }
        if ($interval->d) {
            return $prefix . ' ' . $interval->d . ' ' . ($interval->d > 1 ? 'Tagen' : 'Tag') . $suffix;
        }
        if ($interval->h) {
            return $prefix . ' ' . $interval->h . ' ' . ($interval->h > 1 ? 'Stunden' : 'Stunde') . $suffix;
        }
        if ($interval->i) {
            return $prefix . ' ' . $interval->i . ' ' . ($interval->i > 1 ? 'Minuten' : 'Minute') . $suffix;
        }
        return $isPast ? 'gerade eben' : 'jetzt gleich';
    }
    private function formatDuration(DateTime $start, DateTime $end): string
    {
        $duration = $start->diff($end);
        $formatted = '';
        if ($duration->h > 0) {
            $formatted .= $duration->h . 'h';
        }
        if ($duration->i > 0) {
            if ($formatted !== '') {
                $formatted .= ' '; 
            }
            $formatted .= $duration->i . 'm';
        }
        if ($formatted === '') {
            if ($duration->s > 0 && $duration->h == 0 && $duration->i == 0) {
                 return $duration->s . 's'; 
            }
            return '0m'; 
        }
        return $formatted;
    }
}