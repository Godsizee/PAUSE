<?php
// app/Repositories/SettingsRepository.php

namespace App\Repositories;

use PDO;
use Exception;
use App\Core\Database;

/**
 * Repository zur Verwaltung von Einstellungen in der Datenbank.
 */
class SettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    /**
     * Lädt alle Einstellungen aus der Datenbank.
     *
     * @return array Assoziatives Array [setting_key => setting_value]
     */
    public function loadSettings(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            // Wandelt das Ergebnis [ ['setting_key' => 'k', 'setting_value' => 'v'], ... ]
            // in ein assoziatives Array [ 'k' => 'v' ] um.
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Exception $e) {
            // Loggt den Fehler, aber fährt mit leeren DB-Einstellungen fort (Fallback auf JSON)
            error_log("Could not load settings from DB: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Speichert mehrere Einstellungen in der Datenbank.
     *
     * @param array $settings Assoziatives Array [setting_key => setting_value]
     * @return bool True bei Erfolg
     * @throws Exception Bei Datenbankfehlern
     */
    public function saveSettings(array $settings): bool
    {
        // Verwendet INSERT ... ON DUPLICATE KEY UPDATE für Effizienz
        $sql = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = :value";
        
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($settings as $key => $value) {
                // Konvertiert boolesche Werte und NULL in speicherbare Formate
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                if ($value === null) {
                    $value = ''; // Oder je nach Logik NULL, aber TEXT-Spalte kann '' sein
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

