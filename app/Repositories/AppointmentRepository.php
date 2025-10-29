<?php
// app/Repositories/AppointmentRepository.php

namespace App\Repositories;

use PDO;
use Exception;
use DateTime;
use DateTimeZone;
use DateInterval;
use DatePeriod;

class AppointmentRepository
{
    private PDO $pdo;
    private DateTimeZone $timezone;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->timezone = new DateTimeZone('Europe/Berlin');
    }

    // --- Lehrer: Verfügbarkeit verwalten ---

    /**
     * Fügt ein neues Verfügbarkeitsfenster für einen Lehrer hinzu.
     * @param int $teacherUserId
     * @param int $dayOfWeek (1-5)
     * @param string $startTime (HH:MM)
     * @param string $endTime (HH:MM)
     * @param int $slotDuration (in Minuten)
     * @return int ID der neuen Verfügbarkeit
     * @throws Exception
     */
    public function createAvailability(int $teacherUserId, int $dayOfWeek, string $startTime, string $endTime, int $slotDuration): int
    {
        // TODO: Auf Überlappung mit bestehenden Fenstern prüfen
        $sql = "INSERT INTO teacher_availability (teacher_user_id, day_of_week, start_time, end_time, slot_duration)
                VALUES (:teacher_user_id, :day_of_week, :start_time, :end_time, :slot_duration)";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':teacher_user_id' => $teacherUserId,
            ':day_of_week' => $dayOfWeek,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':slot_duration' => $slotDuration
        ]);

        if (!$success) {
            throw new Exception("Sprechzeit konnte nicht gespeichert werden (eventuell überlappend?).");
        }
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Löscht ein Verfügbarkeitsfenster.
     * @param int $availabilityId
     * @param int $teacherUserId (Zur Sicherheit)
     * @return bool
     */
    public function deleteAvailability(int $availabilityId, int $teacherUserId): bool
    {
        // Löscht auch alle zukünftigen, noch nicht stattgefundenen Termine, die auf diesem Fenster basieren
        $this->pdo->beginTransaction();
        try {
            // 1. Zukünftige Termine löschen
            $sqlDeleteAppointments = "DELETE FROM appointments 
                                      WHERE teacher_user_id = :teacher_user_id 
                                        AND appointment_date >= CURDATE()
                                        AND status = 'booked'
                                        AND appointment_time >= (SELECT start_time FROM teacher_availability WHERE availability_id = :availability_id)
                                        AND appointment_time < (SELECT end_time FROM teacher_availability WHERE availability_id = :availability_id)";
            // HINWEIS: Diese Logik ist vereinfacht. Sie löscht alle Termine des Lehrers an dem Tag im Fenster.
            // Eine bessere Logik würde die availability_id in appointments speichern.
            // Für dieses MVP löschen wir einfach das Fenster.
            
            // TODO: Wenn appointments.availability_id hinzugefügt wird, stattdessen das verwenden:
            // $sqlDeleteAppointments = "DELETE FROM appointments WHERE availability_id = :availability_id AND appointment_date >= CURDATE()";
            // $this->pdo->prepare($sqlDeleteAppointments)->execute([':availability_id' => $availabilityId]);


            // 2. Verfügbarkeitsfenster löschen
            $sqlDeleteAvailability = "DELETE FROM teacher_availability 
                                      WHERE availability_id = :availability_id AND teacher_user_id = :teacher_user_id";
            $stmt = $this->pdo->prepare($sqlDeleteAvailability);
            $stmt->execute([
                ':availability_id' => $availabilityId,
                ':teacher_user_id' => $teacherUserId
            ]);
            
            $this->pdo->commit();
            return $stmt->rowCount() > 0;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Fehler beim Löschen der Sprechzeit: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Holt alle Verfügbarkeitsfenster für einen Lehrer.
     * @param int $teacherUserId
     * @return array
     */
    public function getAvailabilities(int $teacherUserId): array
    {
        $sql = "SELECT * FROM teacher_availability WHERE teacher_user_id = :teacher_user_id ORDER BY day_of_week, start_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_user_id' => $teacherUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    // --- Schüler: Slots abrufen und buchen ---

    /**
     * Holt alle verfügbaren (noch nicht gebuchten) Slots für einen Lehrer an einem bestimmten Datum.
     * @param int $teacherUserId
     * @param string $date (Y-m-d)
     * @return array
     * @throws Exception
     */
    public function getAvailableSlots(int $teacherUserId, string $date): array
    {
        $dateObj = new DateTime($date, $this->timezone);
        $dayOfWeek = (int)$dateObj->format('N'); // 1=Mo, 7=So

        // 1. Hole alle Fenster für diesen Wochentag
        $sqlAvail = "SELECT * FROM teacher_availability 
                     WHERE teacher_user_id = :teacher_user_id AND day_of_week = :day_of_week";
        $stmtAvail = $this->pdo->prepare($sqlAvail);
        $stmtAvail->execute([':teacher_user_id' => $teacherUserId, ':day_of_week' => $dayOfWeek]);
        $availabilities = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);

        if (empty($availabilities)) {
            return []; // Lehrer bietet an diesem Wochentag keine Sprechzeiten an
        }

        // 2. Hole alle bereits gebuchten Termine für diesen Tag
        $sqlBooked = "SELECT appointment_time FROM appointments 
                      WHERE teacher_user_id = :teacher_user_id AND appointment_date = :date AND status = 'booked'";
        $stmtBooked = $this->pdo->prepare($sqlBooked);
        $stmtBooked->execute([':teacher_user_id' => $teacherUserId, ':date' => $date]);
        $bookedTimes = $stmtBooked->fetchAll(PDO::FETCH_COLUMN, 0);
        $bookedSlots = array_flip($bookedTimes); // Macht Zeiten zu Schlüsseln für schnelle Suche

        $availableSlots = [];

        // 3. Generiere Slots aus den Fenstern und filtere gebuchte heraus
        foreach ($availabilities as $window) {
            $start = new DateTime($date . ' ' . $window['start_time'], $this->timezone);
            $end = new DateTime($date . ' ' . $window['end_time'], $this->timezone);
            $duration = $window['slot_duration'];
            $interval = new DateInterval("PT{$duration}M");
            $period = new DatePeriod($start, $interval, $end);

            foreach ($period as $slotStart) {
                $timeString = $slotStart->format('H:i:s'); // z.B. 14:00:00
                $timeStringShort = $slotStart->format('H:i'); // z.B. 14:00

                // Prüfe, ob der Slot bereits gebucht ist
                if (!isset($bookedSlots[$timeString])) {
                    $availableSlots[] = [
                        'time' => $timeString, // Volle Zeit für die Buchung
                        'display' => $timeStringShort, // Angezeigte Zeit
                        'duration' => $duration
                    ];
                }
            }
        }

        return $availableSlots;
    }

    /**
     * Bucht einen Termin für einen Schüler.
     * @param int $studentUserId
     * @param int $teacherUserId
     * @param string $date (Y-m-d)
     * @param string $time (HH:MM:SS)
     * @param int $duration
     * @param string|null $notes
     * @return int ID des neuen Termins
     * @throws Exception
     */
    public function bookAppointment(int $studentUserId, int $teacherUserId, string $date, string $time, int $duration, ?string $notes): int
    {
        // Atomare Operation: INSERT versuchen. Wenn der unique_appointment_slot fehlschlägt,
        // (weil jemand anderes schneller war), wird eine PDOException ausgelöst.
        $sql = "INSERT INTO appointments (student_user_id, teacher_user_id, appointment_date, appointment_time, duration, notes, status)
                VALUES (:student_user_id, :teacher_user_id, :date, :time, :duration, :notes, 'booked')";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':student_user_id' => $studentUserId,
                ':teacher_user_id' => $teacherUserId,
                ':date' => $date,
                ':time' => $time,
                ':duration' => $duration,
                ':notes' => $notes
            ]);
            return (int)$this->pdo->lastInsertId();

        } catch (\PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // 1062 = Duplicate entry
                throw new Exception("Dieser Termin wurde gerade von jemand anderem gebucht. Bitte wählen Sie einen anderen Slot.", 409);
            } else {
                error_log("Fehler bei Terminbuchung: " . $e->getMessage());
                throw new Exception("Ein Fehler ist bei der Buchung aufgetreten.", 500);
            }
        }
    }
    
    // --- Termine abrufen (für "Mein Tag") ---

    /**
     * Holt alle gebuchten Termine eines Schülers in einem Datumsbereich.
     * @param int $studentUserId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAppointmentsForStudent(int $studentUserId, string $startDate, string $endDate): array
    {
        $sql = "SELECT a.*, CONCAT(t.first_name, ' ', t.last_name) as teacher_name, t.teacher_shortcut
                FROM appointments a
                JOIN users u ON a.teacher_user_id = u.user_id
                JOIN teachers t ON u.teacher_id = t.teacher_id
                WHERE a.student_user_id = :student_user_id
                  AND a.status = 'booked'
                  AND a.appointment_date BETWEEN :start_date AND :end_date
                ORDER BY a.appointment_date, a.appointment_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':student_user_id' => $studentUserId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Holt alle gebuchten Termine eines Lehrers in einem Datumsbereich.
     * @param int $teacherUserId
     * @param string $startDate (Y-m-d)
     * @param string $endDate (Y-m-d)
     * @return array
     */
    public function getAppointmentsForTeacher(int $teacherUserId, string $startDate, string $endDate): array
    {
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as student_name, c.class_name
                FROM appointments a
                JOIN users u ON a.student_user_id = u.user_id
                LEFT JOIN classes c ON u.class_id = c.class_id
                WHERE a.teacher_user_id = :teacher_user_id
                  AND a.status = 'booked'
                  AND a.appointment_date BETWEEN :start_date AND :end_date
                ORDER BY a.appointment_date, a.appointment_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':teacher_user_id' => $teacherUserId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Storniert einen Termin (durch Schüler oder Lehrer).
     * @param int $appointmentId
     * @param int $userId (Der stornierende Benutzer)
     * @param string $role (Die Rolle des stornierenden Benutzers)
     * @return bool
     * @throws Exception
     */
    public function cancelAppointment(int $appointmentId, int $userId, string $role): bool
    {
        $sql = "UPDATE appointments SET status = :status 
                WHERE appointment_id = :appointment_id AND ";

        if ($role === 'schueler') {
            $sql .= "student_user_id = :user_id";
            $newStatus = 'cancelled_by_student';
        } elseif ($role === 'lehrer') {
            $sql .= "teacher_user_id = :user_id";
            $newStatus = 'cancelled_by_teacher';
        } else {
            throw new Exception("Nur Schüler oder Lehrer können Termine stornieren.", 403);
        }

        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':status' => $newStatus,
            ':appointment_id' => $appointmentId,
            ':user_id' => $userId
        ]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception("Termin nicht gefunden oder keine Berechtigung zum Stornieren.", 404);
        }
        
        return $success;
    }
}