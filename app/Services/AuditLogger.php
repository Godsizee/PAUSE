<?php
// app/Services/AuditLogger.php
namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Ein einfacher statischer Service zur Protokollierung von Benutzeraktionen.
 */
class AuditLogger
{
    /**
     * Protokolliert eine Aktion in der Datenbank.
     *
     * @param string $action Die durchgeführte Aktion (z.B. 'user_login', 'plan_update').
     * @param ?int $userId Die ID des Benutzers, der die Aktion ausgeführt hat. Wird automatisch aus der Session geholt, falls nicht angegeben.
     * @param ?string $targetType Der Typ der Entität, die beeinflusst wurde (z.B. 'user', 'class', 'plan_entry').
     * @param ?string $targetId Die ID der beeinflussten Entität.
     * @param ?array $details Zusätzliche Informationen (z.B. alte/neue Daten), werden als JSON gespeichert.
     */
    public static function log(string $action, ?string $targetType = null, ?string $targetId = null, ?array $details = null)
    {
        try {
            $pdo = Database::getInstance();
            
            // Hole Benutzer-ID aus der Session, falls vorhanden
            $userId = $_SESSION['user_id'] ?? null;
            // Hole IP-Adresse
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

            $sql = "INSERT INTO audit_logs (user_id, ip_address, action, target_type, target_id, details)
                    VALUES (:user_id, :ip_address, :action, :target_type, :target_id, :details)";

            $stmt = $pdo->prepare($sql);
            
            $stmt->execute([
                ':user_id' => $userId,
                ':ip_address' => $ipAddress,
                ':action' => $action,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':details' => $details ? json_encode($details) : null
            ]);

        } catch (\Exception $e) {
            // Fehler beim Loggen sollte die Hauptanwendung nicht stoppen.
            // Fehler im PHP-Error-Log protokollieren.
            error_log("AuditLogger Fehler: Konnte Aktion '{$action}' nicht protokollieren. Fehler: " . $e->getMessage());
        }
    }
}
