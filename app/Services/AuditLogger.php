<?php
namespace App\Services;
use App\Core\Database;
use PDO;
class AuditLogger
{
    public static function log(string $action, ?string $targetType = null, ?string $targetId = null, ?array $details = null)
    {
        try {
            $pdo = Database::getInstance();
            $userId = $_SESSION['user_id'] ?? null;
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
            error_log("AuditLogger Fehler: Konnte Aktion '{$action}' nicht protokollieren. Fehler: " . $e->getMessage());
        }
    }
}