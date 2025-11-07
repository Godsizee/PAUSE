<?php
// app/Services/AppointmentService.php

// MODIFIZIERT:
// 1. Neue öffentliche Methoden getAppointmentsForStudent() und getAppointmentsForTeacher() hinzugefügt.
// 2. Diese Methoden kapseln den Zugriff auf das private $appointmentRepo.

namespace App\Services;

use App\Repositories\AppointmentRepository;
use App\Repositories\UserRepository;
use PDO;
use Exception;
use DateTime;
use DateTimeZone;
use DateInterval;
use DatePeriod;

/**
 * Service-Klasse zur Kapselung der gesamten Geschäftslogik
 * für Sprechstunden (Verfügbarkeiten und Buchungen).
 * (Ausgelagert aus TeacherController und DashboardController).
 */
class AppointmentService
{
    // KORREKTUR: Repository ist jetzt privat
    private AppointmentRepository $appointmentRepo;
    private UserRepository $userRepo;
    private DateTimeZone $timezone;

    public function __construct(AppointmentRepository $appointmentRepo, UserRepository $userRepo)
    {
        $this->appointmentRepo = $appointmentRepo;
        $this->userRepo = $userRepo;
        $this->timezone = new DateTimeZone('Europe/Berlin');
    }

    // --- Logik aus TeacherController ---

    /**
     * Holt die definierten Sprechstundenfenster eines Lehrers.
     * (Unverändert)
     */
    public function getAvailabilities(int $teacherUserId): array
    {
        return $this->appointmentRepo->getAvailabilities($teacherUserId);
    }

    /**
     * Erstellt ein neues Sprechstundenfenster für einen Lehrer.
     * (Unverändert)
     */
    public function createAvailability(int $teacherUserId, array $data): int
    {
        // ... (Logik unverändert) ...
        $dayOfWeek = filter_var($data['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        $startTime = $data['start_time'] ?? null;
        $endTime = $data['end_time'] ?? null;
        $slotDuration = filter_var($data['slot_duration'] ?? 15, FILTER_VALIDATE_INT);

        if (!$dayOfWeek || !$startTime || !$endTime || !$slotDuration || $dayOfWeek < 1 || $dayOfWeek > 5 || $slotDuration < 5) {
            throw new Exception("Ungültige Eingabedaten.", 400);
        }

        if ($startTime >= $endTime) {
            throw new Exception("Startzeit muss vor der Endzeit liegen.", 400);
        }

        return $this->appointmentRepo->createAvailability(
            $teacherUserId,
            $dayOfWeek,
            $startTime,
            $endTime,
            $slotDuration
        );
    }

    /**
     * Löscht ein Sprechstundenfenster eines Lehrers.
     * (Unverändert)
     */
    public function deleteAvailability(int $teacherUserId, int $availabilityId): bool
    {
        // ... (Logik unverändert) ...
     */
    public function deleteAvailability(int $teacherUserId, int $availabilityId): bool
    {
        if (!$availabilityId) {
            throw new Exception("Keine ID angegeben.", 400);
        }

        // Repository wirft 404, wenn nicht gefunden oder keine Berechtigung
        $success = $this->appointmentRepo->deleteAvailability($availabilityId, $teacherUserId);

        if (!$success) {
             throw new Exception("Sprechzeit nicht gefunden oder keine Berechtigung.", 404);
        }
        
        return true;
    }


    // --- Logik aus DashboardController ---

    /**
     * Holt alle verfügbaren (noch nicht gebuchten) Slots für einen Lehrer an einem Datum.
     * (Unverändert)
     */
    public function getAvailableSlots(int $teacherStammdatenId, string $date): array
    {
        // ... (Logik unverändert) ...
     */
    public function getAvailableSlots(int $teacherStammdatenId, string $date): array
    {
        if (!$teacherStammdatenId || !$date || DateTime::createFromFormat('Y-m-d', $date) === false) {
            throw new Exception("Ungültige Lehrer-ID oder Datum.", 400);
        }
        
        // 1. Finde die user_id des Lehrers anhand der Stammdaten-ID
        $teacherUser = $this->userRepo->findUserByTeacherId($teacherStammdatenId);
        if (!$teacherUser) {
            throw new Exception("Lehrerprofil (Benutzer) nicht gefunden.", 404);
        }
        $teacherUserId = $teacherUser['user_id'];
        
        // 2. Prüfe Datum (Vergangenheit)
        $today = (new DateTime('now', $this->timezone))->format('Y-m-d');
        $slots = [];
        if ($date < $today) {
             $slots = $this->appointmentRepo->getAvailableSlots($teacherUserId, $date);
             // Erlaube Anzeige, wenn Slots vorhanden, aber werfe Fehler, wenn keine Slots UND in Vergangenheit
             if (empty($slots)) {
                  throw new Exception("Termine können nicht in der Vergangenheit gebucht werden.", 400);
             }
        } else {
             $slots = $this->appointmentRepo->getAvailableSlots($teacherUserId, $date);
        }
        
        return $slots;
    }

    /**
     * Bucht einen Termin für einen Schüler.
     * (Unverändert)
     */
    public function bookAppointment(int $studentUserId, array $data): int
    {
        // ... (Logik unverändert) ...
     */
    public function bookAppointment(int $studentUserId, array $data): int
    {
        $teacherStammdatenId = filter_var($data['teacher_id'] ?? null, FILTER_VALIDATE_INT);
        $date = $data['date'] ?? null;
        $time = $data['time'] ?? null;
        $duration = filter_var($data['duration'] ?? null, FILTER_VALIDATE_INT);
        $notes = isset($data['notes']) ? trim($data['notes']) : null;

        if (!$teacherStammdatenId || !$date || !$time || !$duration) {
            throw new Exception("Fehlende Daten für die Buchung.", 400);
        }
        
        // 1. Finde die user_id des Lehrers
        $teacherUser = $this->userRepo->findUserByTeacherId($teacherStammdatenId);
        if (!$teacherUser) {
            throw new Exception("Lehrerprofil (Benutzer) nicht gefunden.", 404);
        }
        $teacherUserId = $teacherUser['user_id'];
        
        // 2. Prüfe Datum (Vergangenheit)
        $today = (new DateTime('now', $this->timezone))->format('Y-m-d');
        if ($date < $today) {
             throw new Exception("Termine können nicht in der Vergangenheit gebucht werden.", 400);
        }

        // 3. Buchung im Repository (wirft 409 bei Konflikt)
        return $this->appointmentRepo->bookAppointment(
            $studentUserId,
            $teacherUserId,
            $date,
            $time,
            $duration,
            $notes
        );
    }

    /**
     * Storniert einen Termin (durch Schüler oder Lehrer).
     * (Unverändert)
     */
    public function cancelAppointment(int $appointmentId, int $userId, string $role): bool
    {
        // ... (Logik unverändert) ...

    public function cancelAppointment(int $appointmentId, int $userId, string $role): bool
    {
        if (!$appointmentId) {
            throw new Exception("Keine Termin-ID angegeben.", 400);
        }

        // Repository wirft 403/404 bei Fehlern
        $success = $this->appointmentRepo->cancelAppointment($appointmentId, $userId, $role);
        
        if (!$success) {
            // Sollte nicht passieren, wenn Repo Exceptions wirft, aber zur Sicherheit
            throw new Exception("Termin konnte nicht storniert werden oder Berechtigung fehlt.", 500);
        }
        
        return true;
    }

    // --- NEUE ÖFFENTLICHE METHODEN (Wrapper für das Repository) ---

    /**
     * Holt alle gebuchten Termine eines Schülers in einem Datumsbereich.
     * (Wrapper für DashboardController)
     *
     * @param int $studentUserId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAppointmentsForStudent(int $studentUserId, string $startDate, string $endDate): array
    {
        return $this->appointmentRepo->getAppointmentsForStudent($studentUserId, $startDate, $endDate);
    }

    /**
     * Holt alle gebuchten Termine eines Lehrers in einem Datumsbereich.
     * (Wrapper für DashboardController)
     *
     * @param int $teacherUserId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAppointmentsForTeacher(int $teacherUserId, string $startDate, string $endDate): array
    {
        return $this->appointmentRepo->getAppointmentsForTeacher($teacherUserId, $startDate, $endDate);
    }
}