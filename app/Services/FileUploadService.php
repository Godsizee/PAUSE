<?php
namespace App\Services;
use Exception;
class FileUploadService
{
    private string $baseUploadDir = 'uploads/';
    private string $publicPath;
    public function __construct()
    {
        $this->publicPath = dirname(__DIR__, 2) . '/public/';
    }
    public function handleUpload(string $fileKey, string $subDirectory, array $allowedMimes, int $maxSize = 5 * 1024 * 1024): string
    {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Keine Datei hochgeladen oder Fehler beim Upload (Code: {$_FILES[$fileKey]['error']}).", 400);
        }
        $file = $_FILES[$fileKey];
        if ($file['size'] > $maxSize) {
            throw new Exception("Datei ist zu groß (Max: " . ($maxSize / 1024 / 1024) . "MB).", 400);
        }
        $fileType = mime_content_type($file['tmp_name']);
        if (!array_key_exists($fileType, $allowedMimes)) {
            throw new Exception("Ungültiger Dateityp. Erlaubt: " . implode(', ', array_keys($allowedMimes)), 400);
        }
        $targetDirectory = $this->publicPath . $this->baseUploadDir . $subDirectory;
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0775, true)) {
            error_log("Konnte Upload-Verzeichnis nicht erstellen: " . $targetDirectory);
            throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden.", 500);
        }
        if (!is_writable($targetDirectory)) {
            error_log("Upload-Verzeichnis nicht beschreibbar: " . $targetDirectory);
            throw new Exception("Upload-Verzeichnis ist nicht beschreibbar.", 500);
        }
        $extension = $allowedMimes[$fileType];
        $fileName = $fileKey . '_' . uniqid() . '.' . $extension;
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $relativePath = $this->baseUploadDir . $subDirectory . '/' . $fileName;
            return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        } else {
            error_log("Fehler beim Verschieben der hochgeladenen Datei nach: " . $targetPath);
            throw new Exception("Fehler beim Verschieben der hochgeladenen Datei.", 500);
        }
    }
    public function deleteFile(?string $relativePath): bool
    {
        if (empty($relativePath)) {
            return true; 
        }
        $absolutePath = $this->publicPath . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (file_exists($absolutePath)) {
            if (@unlink($absolutePath)) {
                return true;
            } else {
                error_log("Konnte Datei nicht löschen: " . $absolutePath);
                return false; 
            }
        }
        return true; 
    }
}