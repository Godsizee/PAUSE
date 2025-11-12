<?php
namespace App\Repositories;
use PDO;
use Exception;
use App\Core\Database;
class SettingsRepository
{
    private PDO $pdo;
    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }
    public function loadSettings(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {
            error_log("Could not load settings from DB: " . $e->getMessage());
            return [];
        }
    }
    public function saveSettings(array $settings): bool
    {
        $sql = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = :value";
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare($sql);
            foreach ($settings as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                if ($value === null) {
                    $value = ''; 
                }
                $stmt->execute([':key' => $key, ':value' => $value]);
            }
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Failed to save settings: " . $e->getMessage());
            throw new Exception("Einstellungen konnten nicht gespeichert werden: " . $e->getMessage());
        }
    }
}