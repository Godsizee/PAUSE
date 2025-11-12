<?php
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

    public function createAvailability(int $teacherUserId, int $dayOfWeek, string $startTime, string $endTime, int $slotDuration, ?string $location): int
    {
        $sql = "INSERT INTO teacher_availability (teacher_user_id, day_of_week, start_time, end_time, slot_duration, location)
              VALUES (:teacher_user_id, :day_of_week, :start_time, :end_time, :slot_duration, :location)";
        $stmt = $this->pdo->prepare($sql);
        $success = $stmt->execute([
            ':teacher_user_id' => $teacherUserId,
            ':day_of_week' => $dayOfWeek,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':slot_duration' => $slotDuration,
            ':location' => $location
        ]);
        if (!$success) {
            throw new Exception("Sprechzeit konnte nicht gespeichert werden (eventuell überlappend?).");
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteAvailability(int $availabilityId, int $teacherUserId): bool
    {
        $this->pdo->beginTransaction();
        try {
            // Zukünftige gebuchte Termine löschen, die zu diesem Fenster gehören
            $sqlDeleteAppointments = "DELETE a FROM appointments a
                                      JOIN teacher_availability ta ON a.teacher_user_id = ta.teacher_user_id
                                      WHERE a.teacher_user_id = :teacher_user_id
                                        AND a.availability_id = :availability_id
                                        AND a.appointment_date >= CURDATE()
                                        AND a.status = 'booked'";

            $stmtDelApp = $this->pdo->prepare($sqlDeleteAppointments);
            $stmtDelApp->execute([
                ':teacher_user_id' => $teacherUserId,
                ':availability_id' => $availabilityId
            ]);
            
            // Das Verfügbarkeitsfenster selbst löschen
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

    public function getAvailabilities(int $teacherUserId): array
    {
        $sql = "SELECT * FROM teacher_availability WHERE teacher_user_id = :teacher_user_id ORDER BY day_of_week, start_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':teacher_user_id' => $teacherUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Holt alle verfügbaren (nicht gebuchten) Slots für einen Lehrer in einem zukünftigen Zeitraum.
     */
    public function getUpcomingAvailableSlots(int $teacherUserId, int $daysInFuture = 14): array
    {
        // 1. Alle Verfügbarkeitsfenster des Lehrers holen
        $availabilities = $this->getAvailabilities($teacherUserId);
        if (empty($availabilities)) {
            return [];
        }

        $today = new DateTime('now', $this->timezone);
        $today->setTime(0, 0, 0);
        $endDate = (new DateTime('now', $this->timezone))->modify("+{$daysInFuture} days");

        // 2. Alle gebuchten Termine in diesem Zeitraum holen
        $sqlBooked = "SELECT appointment_date, appointment_time FROM appointments
                           WHERE teacher_user_id = :teacher_user_id
                             AND appointment_date BETWEEN :start_date AND :end_date
                             AND status = 'booked'";
        $stmtBooked = $this->pdo->prepare($sqlBooked);
        $stmtBooked->execute([
            ':teacher_user_id' => $teacherUserId,
            ':start_date' => $today->format('Y-m-d'),
            ':end_date' => $endDate->format('Y-m-d')
        ]);
        
        $bookedSlots = [];
        foreach ($stmtBooked->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bookedSlots[$row['appointment_date'] . '_' . $row['appointment_time']] = true;
        }

        // 3. Alle Slots generieren und gegen gebuchte Slots prüfen
        $availableSlots = [];
        $currentDate = $today;
        $dateInterval = new DateInterval('P1D');
        $dateRange = new DatePeriod($currentDate, $dateInterval, $endDate->modify('+1 day')); // +1, damit der letzte Tag inklusiv ist

        foreach ($dateRange as $date) {
            $dayOfWeek = (int)$date->format('N'); // 1 (Mon) - 7 (Son)
            
            // Überspringe Wochenenden
            if ($dayOfWeek > 5) {
                continue;
            }

            // Finde passende Fenster für diesen Wochentag
            $windowsForDay = array_filter($availabilities, fn($av) => $av['day_of_week'] == $dayOfWeek);
            $dateString = $date->format('Y-m-d');

            foreach ($windowsForDay as $window) {
                $start = new DateTime($dateString . ' ' . $window['start_time'], $this->timezone);
                $end = new DateTime($dateString . ' ' . $window['end_time'], $this->timezone);
                $duration = $window['slot_duration'];
                $interval = new DateInterval("PT{$duration}M");
                $slotPeriod = new DatePeriod($start, $interval, $end);

                foreach ($slotPeriod as $slotStart) {
                    $timeString = $slotStart->format('H:i:s');
                    $timeStringShort = $slotStart->format('H:i');
                    
                    // Prüfen, ob Slot bereits gebucht ist
                    if (!isset($bookedSlots[$dateString . '_' . $timeString])) {
                        $availableSlots[] = [
                            'date' => $dateString,
                            'time' => $timeString,
                            'display' => $timeStringShort,
                            'duration' => $duration,
                            'location' => $window['location'] ?? 'N/A', // Standort hinzufügen
                            'availability_id' => $window['availability_id'] // ID des Fensters hinzufügen
                        ];
                    }
                }
            }
        }
        
        return $availableSlots;
    }

    public function bookAppointment(int $studentUserId, int $teacherUserId, int $availabilityId, string $date, string $time, int $duration, ?string $location, ?string $notes): int
    {
        $sql = "INSERT INTO appointments (student_user_id, teacher_user_id, availability_id, appointment_date, appointment_time, duration, location, notes, status)
              VALUES (:student_user_id, :teacher_user_id, :availability_id, :date, :time, :duration, :location, :notes, 'booked')";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':student_user_id' => $studentUserId,
                ':teacher_user_id' => $teacherUserId,
                ':availability_id' => $availabilityId,
                ':date' => $date,
                ':time' => $time,
                ':duration' => $duration,
                ':location' => $location,
                ':notes' => $notes
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                throw new Exception("Dieser Termin wurde gerade von jemand anderem gebucht. Bitte wählen Sie einen anderen Slot.", 409);
            } else {
                error_log("Fehler bei Terminbuchung: " . $e->getMessage());
                throw new Exception("Ein Fehler ist bei der Buchung aufgetreten.", 500);
            }
        }
    }

    public function getAppointmentsForStudent(int $studentUserId, string $startDate, string $endDate): array
    {
        $sql = "SELECT a.*, CONCAT(t.first_name, ' ', t.last_name) as teacher_name, t.teacher_shortcut, a.location
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

    public function getAppointmentsForTeacher(int $teacherUserId, string $startDate, string $endDate): array
    {
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as student_name, c.class_name, c.class_id, a.location
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

    public function getAllAppointmentsForTeacher(int $teacherUserId, string $sortOrder = 'DESC'): array
    {
        // NEU: a.notes und a.location hinzugefügt
        $sql = "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) as student_name, c.class_name, c.class_id, a.notes, a.location
                FROM appointments a
                JOIN users u ON a.student_user_id = u.user_id
                LEFT JOIN classes c ON u.class_id = c.class_id
                WHERE a.teacher_user_id = :teacher_user_id
                  AND a.status = 'booked'
                ORDER BY a.appointment_date " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC') . ", a.appointment_time " . ($sortOrder === 'DESC' ? 'DESC' : 'ASC');
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':teacher_user_id' => $teacherUserId
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}