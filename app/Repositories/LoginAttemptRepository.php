<?php
namespace App\Repositories;
use App\Core\Utils; 
use PDO;
class LoginAttemptRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public function isAllowed(string $identifier): bool
    {
        $settings = Utils::getSettings();
        $maxAttempts = $settings['max_login_attempts'];
        $lockoutMinutes = $settings['lockout_minutes'];
        $sql = "SELECT COUNT(*) FROM login_attempts
                WHERE identifier = :identifier
                AND attempt_time > (NOW() - INTERVAL :lockout MINUTE)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':identifier' => $identifier,
            ':lockout' => $lockoutMinutes 
        ]);
        $attempts = (int)$stmt->fetchColumn();
        return $attempts < $maxAttempts; 
    }
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
    public function clearAttempts(string $identifier): void
    {
        $sql = "DELETE FROM login_attempts WHERE identifier = :identifier";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':identifier' => $identifier]);
    }
}