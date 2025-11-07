<?php
// app/Services/FileUploadService.php

namespace App\Services;

use Exception;

/**
 * Service-Klasse zur Kapselung der Logik für Datei-Uploads.
 * Stellt eine wiederverwendbare Methode zur sicheren Verarbeitung
 * und Speicherung von hochgeladenen Dateien bereit.
 */
class FileUploadService
{
    /**
     * Basis-Upload-Verzeichnis (relativ zum 'public' Ordner).
     * @var string
     */
    private string $baseUploadDir = 'uploads/';

    /**
     * Der absolute Pfad zum 'public' Verzeichnis.
     * @var string
     */
    private string $publicPath;

    public function __construct()
    {
        // Geht 3 Ebenen vom 'app/Services' Verzeichnis hoch zum Projektstamm,
        // dann in 'public'.
        $this->publicPath = dirname(__DIR__, 2) . '/public/';
    }

    /**
     * Verarbeitet einen einzelnen Datei-Upload.
     *
     * @param string $fileKey Der Schlüssel in der $_FILES-Variable (z.B. 'site_logo' oder 'attachment').
     * @param string $subDirectory Das Unterverzeichnis (z.B. 'branding' oder 'announcements').
     * @param array $allowedMimes Erlaubte MIME-Typen (Format: ['mime/type' => 'extension']).
     * @param int $maxSize (in Bytes) Die maximale Dateigröße.
     * @return string Der relative Pfad zur gespeicherten Datei (z.B. 'uploads/branding/logo_xyz.png').
     * @throws Exception Wenn der Upload fehlschlägt (Validierung, Verschieben).
     */
    public function handleUpload(string $fileKey, string $subDirectory, array $allowedMimes, int $maxSize = 5 * 1024 * 1024): string
    {
        // 1. Prüfen, ob eine Datei hochgeladen wurde und kein Fehler vorliegt
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Keine Datei hochgeladen oder Fehler beim Upload (Code: {$_FILES[$fileKey]['error']}).", 400);
        }

        $file = $_FILES[$fileKey];

        // 2. Größe prüfen
        if ($file['size'] > $maxSize) {
            throw new Exception("Datei ist zu groß (Max: " . ($maxSize / 1024 / 1024) . "MB).", 400);
        }

        // 3. MIME-Typ validieren
        $fileType = mime_content_type($file['tmp_name']);
        if (!array_key_exists($fileType, $allowedMimes)) {
            throw new Exception("Ungültiger Dateityp. Erlaubt: " . implode(', ', array_keys($allowedMimes)), 400);
        }

        // 4. Zielverzeichnis erstellen
        $targetDirectory = $this->publicPath . $this->baseUploadDir . $subDirectory;
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true)) {
            error_log("Konnte Upload-Verzeichnis nicht erstellen: " . $targetDirectory);
            throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden.", 500);
        }
        if (!is_writable($targetDirectory)) {
            error_log("Upload-Verzeichnis nicht beschreibbar: " . $targetDirectory);
            throw new Exception("Upload-Verzeichnis ist nicht beschreibbar.", 500);
        }

        // 5. Sicherer Dateiname generieren
        $extension = $allowedMimes[$fileType];
        // Dateiname basiert auf dem $fileKey und einer unique ID
        $fileName = $fileKey . '_' . uniqid() . '.' . $extension;
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $fileName;

        // 6. Datei verschieben
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // 7. Relativen Pfad für die DB zurückgeben
            // (Ersetze Backslashes (Windows) durch Slashes für Web-Pfade)
            $relativePath = $this->baseUploadDir . $subDirectory . '/' . $fileName;
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        } else {
            error_log("Fehler beim Verschieben der hochgeladenen Datei nach: " . $targetPath);
            throw new Exception("Fehler beim Verschieben der hochgeladenen Datei.", 500);
        }
    }

    /**
     * Löscht eine Datei basierend auf ihrem relativen Pfad.
     *
     * @param string|null $relativePath Der relative Pfad (z.B. 'uploads/branding/logo_xyz.png').
     * @return bool True bei Erfolg oder wenn $relativePath null war, False bei Fehler.
     */
    public function deleteFile(?string $relativePath): bool
    {
        if (empty($relativePath)) {
            return true; // Nichts zu tun
        }

        $absolutePath = $this->publicPath . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if (file_exists($absolutePath)) {
            if (@unlink($absolutePath)) {
                return true;
            } else {
                error_log("Konnte Datei nicht löschen: " . $absolutePath);
                return false; // Fehler beim Löschen, aber wir werfen keine Exception
            }
        }
        
        return true; // Datei existierte nicht, also "erfolgreich" gelöscht
    }
}