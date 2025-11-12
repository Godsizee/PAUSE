<?php
namespace App\Core; 
class Cache
{
    private static function getCacheDir(): string
    {
        return dirname(__DIR__, 2) . '/cache/';
    }
    public static function clearAll(): array
    {
        $cacheDir = self::getCacheDir();
        if (!is_dir($cacheDir)) {
            return ['success' => true, 'message' => 'Cache-Verzeichnis existiert nicht, nichts zu tun.'];
        }
        $files = glob($cacheDir . '*.cache');
        $successCount = 0;
        $failCount = 0;
        if ($files === false) {
            error_log("Fehler beim Lesen des Cache-Verzeichnisses: " . $cacheDir);
            return ['success' => false, 'message' => 'Fehler beim Lesen des Cache-Verzeichnisses.'];
        }
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) { 
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
}