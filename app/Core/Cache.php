<?php
// app/Core/Cache.php
// MODIFIZIERT: Logik zum Löschen des Caches hinzugefügt.

namespace App\Core; // KORRIGIERT: Namespace muss App\Core sein, nicht App

class Cache
{
    /**
     * Definiert das Cache-Verzeichnis.
     * Liegt im Root-Verzeichnis des Projekts.
     */
    private static function getCacheDir(): string
    {
        // __DIR__ ist app/Core
        // Wir wollen <projekt_root>/cache/
        return dirname(__DIR__, 2) . '/cache/';
    }

    /**
     * Löscht alle .cache-Dateien aus dem Cache-Verzeichnis.
     *
     * @return array [success (bool), message (string)]
     */
    public static function clearAll(): array
    {
        $cacheDir = self::getCacheDir();

        if (!is_dir($cacheDir)) {
            // Wenn das Verzeichnis nicht existiert, ist das kein Fehler,
            // es gibt einfach nichts zu tun.
            return ['success' => true, 'message' => 'Cache-Verzeichnis existiert nicht, nichts zu tun.'];
        }

        // Finde alle .cache Dateien
        $files = glob($cacheDir . '*.cache');
        $successCount = 0;
        $failCount = 0;

        if ($files === false) {
            // Fehler beim Lesen des Verzeichnisses
            error_log("Fehler beim Lesen des Cache-Verzeichnisses: " . $cacheDir);
            return ['success' => false, 'message' => 'Fehler beim Lesen des Cache-Verzeichnisses.'];
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) { // @ unterdrückt Warnungen, falls Datei inzwischen weg ist
                    $successCount++;
                } else {
                    $failCount++;
                    error_log("Konnte Cache-Datei nicht löschen: " . $file);
                }
            }
        }

        if ($failCount > 0) {
            return [
                'success' => false,
                'message' => "Konnte $failCount von " . ($successCount + $failCount) . " Cache-Dateien nicht löschen. Details im Server-Log."
            ];
        }

        if ($successCount === 0) {
            return ['success' => true, 'message' => 'App-Cache war bereits leer.'];
        }

        return ['success' => true, 'message' => "Erfolgreich $successCount App-Cache-Datei(en) gelöscht."];
    }
    
    // Zukünftige Cache-Methoden (get, set, etc.) würden hier hinkommen
    // public static function get($key) { ... }
    // public static function set($key, $data, $ttl) { ... }
}