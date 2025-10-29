<?php
// app/Repositories/LoginAttemptRepository.php
namespace App\Repositories;

use App\Core\Utils; // NEU: Utils importieren
use PDO;

class LoginAttemptRepository
{
    private PDO $pdo;
    // VERALTET: Konstanten werden durch Einstellungen ersetzt
    // private const MAX_ATTEMPTS = 5;
    // private const LOCKOUT_MINUTES = 15;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Prüft, ob ein Login-Versuch für den gegebenen Identifier erlaubt ist.
     * Gibt `false` zurück, wenn die maximale Anzahl an Versuchen im Lockout-Zeitraum überschritten wurde.
     */
    public function isAllowed(string $identifier): bool
    {
        // NEU: Hole Werte aus den Einstellungen
        $settings = Utils::getSettings();
        $maxAttempts = $settings['max_login_attempts'];
        $lockoutMinutes = $settings['lockout_minutes'];

        $sql = "SELECT COUNT(*) FROM login_attempts
                WHERE identifier = :identifier
                AND attempt_time > (NOW() - INTERVAL :lockout MINUTE)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':lockout' => $lockoutMinutes // Verwende den Wert aus den Einstellungen
        ]);

        $attempts = (int)$stmt->fetchColumn();

        return $attempts < $maxAttempts; // Vergleiche mit dem Wert aus den Einstellungen
    }

    /**
     * Speichert einen fehlgeschlagenen Login-Versuch in der Datenbank.
     */
    public function recordFailure(string $identifier): void
    {
        $sql = "INSERT INTO login_attempts (identifier, ip_address, attempt_time)
                VALUES (:identifier, :ip_address, NOW())";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
        ]);
    }

    /**
     * Löscht alle Login-Versuche für einen Identifier nach einem erfolgreichen Login.
     */
    public function clearAttempts(string $identifier): void
    {
        $sql = "DELETE FROM login_attempts WHERE identifier = :identifier";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);
    }
}