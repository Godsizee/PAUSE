<?php
// app/Http/Controllers/Admin/SettingsController.php

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Repositories\SettingsRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;

class SettingsController
{
    private SettingsRepository $settingsRepo;
    private string $uploadDir; // NEU: Upload-Verzeichnis

    public function __construct()
    {
        $this->settingsRepo = new SettingsRepository();
        // NEU: Definiere das Upload-Verzeichnis für Branding
        $this->uploadDir = dirname(__DIR__, 4) . '/public/uploads/branding/';
    }

    /**
     * Zeigt die Hauptseite für die Anwendungseinstellungen an.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Anwendungs-Einstellungen';
        $body_class = 'admin-dashboard-body';

        // Aktuelle Einstellungen laden, um sie im Formular anzuzeigen
        $currentSettings = Utils::getSettings();

        // NEU: Sicherstellen, dass das Upload-Verzeichnis existiert
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }

        Security::getCsrfToken(); // Stellt sicher, dass ein Token für das Formular existiert

        include_once dirname(__DIR__, 4) . '/pages/admin/settings.php';
    }

    /**
     * NEU: Verarbeitet Datei-Uploads für Logos/Favicons.
     * @param string $fileKey Der Schlüssel in der $_FILES-Variable (z.B. 'site_logo')
     * @param array $allowedMimes Erlaubte MIME-Typen (Format: ['mime/type' => 'extension'])
     * @param string|null $currentPath Der Pfad zur aktuell gespeicherten Datei (zum Löschen)
     * @return string|null Der relative Pfad zur neuen Datei oder $currentPath, wenn nichts hochgeladen wurde
     * @throws Exception
     */
    private function handleFileUpload(string $fileKey, array $allowedMimes, ?string $currentPath): ?string
    {
        // Prüfen, ob eine neue Datei hochgeladen wurde
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES[$fileKey];
            $fileType = mime_content_type($file['tmp_name']);

            // KORREKTUR: Prüfe, ob der fileType ein SCHLÜSSEL im $allowedMimes Array ist
            if (!array_key_exists($fileType, $allowedMimes)) {
                throw new Exception("Ungültiger Dateityp für '{$fileKey}'. Erlaubt: " . implode(', ', array_keys($allowedMimes)), 400);
            }

            // Sicherstellen, dass das Verzeichnis existiert
            if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true)) {
                throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden.", 500);
            }
            if (!is_writable($this->uploadDir)) {
                throw new Exception("Upload-Verzeichnis ist nicht beschreibbar.", 500);
            }

            // Sicherer Dateiname
            // KORREKTUR: Verwende die Extension aus dem $allowedMimes Array, nicht aus dem Originalnamen
            $extension = $allowedMimes[$fileType]; 
            $fileName = $fileKey . '_' . uniqid() . '.' . $extension;
            $targetPath = $this->uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Erfolgreich hochgeladen, lösche die alte Datei (falls vorhanden)
                if ($currentPath && file_exists(dirname(__DIR__, 4) . '/public/' . $currentPath)) {
                    @unlink(dirname(__DIR__, 4) . '/public/' . $currentPath);
                }
                // Gebe den *relativen* Pfad für die DB zurück
                return 'uploads/branding/' . $fileName;
            } else {
                throw new Exception("Fehler beim Verschieben der hochgeladenen Datei.", 500);
            }
        }

        // Prüfen, ob die aktuelle Datei entfernt werden soll
        if (isset($_POST['remove_' . $fileKey]) && $_POST['remove_' . $fileKey] === '1') {
            if ($currentPath && file_exists(dirname(__DIR__, 4) . '/public/' . $currentPath)) {
                @unlink(dirname(__DIR__, 4) . '/public/' . $currentPath);
            }
            return null; // Pfad aus der DB entfernen
        }

        // Keine Änderung, behalte den alten Pfad
        return $currentPath;
    }


    /**
     * API: Speichert die Anwendungseinstellungen.
     */
    public function save()
    {
        Security::requireRole('admin');
        header('Content-Type: application/json');

        try {
            Security::verifyCsrfToken();

            // Daten kommen jetzt als Form-Daten (multipart/form-data)
            $data = $_POST;
            
            // Hole aktuelle Einstellungen (wichtig für Datei-Pfade)
            $oldSettings = Utils::getSettings();

            // --- Validierungen ---
            $startHour = filter_var($data['default_start_hour'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            $endHour = filter_var($data['default_end_hour'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
            if ($startHour === false || $endHour === false || $startHour >= $endHour) {
                throw new Exception("Ungültiger Stundenbereich (1-12, Start < Ende).", 400);
            }
            $maxAttempts = filter_var($data['max_login_attempts'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            $lockoutMinutes = filter_var($data['lockout_minutes'] ?? 15, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
            if ($maxAttempts === false || $lockoutMinutes === false) {
                throw new Exception("Ungültige Werte für Login-Sperre.", 400);
            }
            $defaultTheme = $data['default_theme'] ?? 'light';
            if (!in_array($defaultTheme, ['light', 'dark'])) {
                $defaultTheme = 'light';
            }
            $icalWeeksFuture = filter_var($data['ical_weeks_future'] ?? 8, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 52]]);
            if ($icalWeeksFuture === false) {
                throw new Exception("Ungültige Anzahl an iCal-Wochen (1-52).", 400);
            }
            // NEU: Bereinige IP-Whitelist-String
            $whitelistIPs = $data['maintenance_whitelist_ips'] ?? '';
            // Entferne alles außer IPs, Kommas, Doppelpunkte (IPv6) und Punkte
            $whitelistIPs = preg_replace('/[^0-9a-fA-F:.,\s]/', '', $whitelistIPs);
            // Ersetze Leerzeichen und Zeilenumbrüche durch Kommas und entferne doppelte Kommas
            $whitelistIPs = preg_replace('/[\s,]+/', ',', $whitelistIPs);
            $whitelistIPs = trim($whitelistIPs, ',');


            // --- Datei-Uploads verarbeiten ---
            $logoPath = $this->handleFileUpload(
                'site_logo',
                ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/gif' => 'gif'],
                $oldSettings['site_logo_path'] ?? null
            );

            $faviconPath = $this->handleFileUpload(
                'site_favicon',
                ['image/x-icon' => 'ico', 'image/png' => 'png', 'image/svg+xml' => 'svg'],
                $oldSettings['site_favicon_path'] ?? null
            );


            // Aufbereitete Einstellungen für das Repository
            $settingsToSave = [
                'site_title' => trim($data['site_title'] ?? 'PAUSE Portal'),
                'maintenance_mode' => (isset($data['maintenance_mode']) && ($data['maintenance_mode'] === 'on' || $data['maintenance_mode'] === '1')) ? '1' : '0',
                'maintenance_message' => trim($data['maintenance_message'] ?? ''),
                'maintenance_whitelist_ips' => $whitelistIPs, // NEU
                'default_start_hour' => $startHour,
                'default_end_hour' => $endHour,
                'max_login_attempts' => $maxAttempts,
                'lockout_minutes' => $lockoutMinutes,
                'site_logo_path' => $logoPath,
                'site_favicon_path' => $faviconPath,
                'default_theme' => $defaultTheme,
                'ical_enabled' => (isset($data['ical_enabled']) && ($data['ical_enabled'] === 'on' || $data['ical_enabled'] === '1')) ? '1' : '0',
                'ical_weeks_future' => $icalWeeksFuture,
                'pdf_footer_text' => trim($data['pdf_footer_text'] ?? ''),
                // NEU: Community Board
                'community_board_enabled' => (isset($data['community_board_enabled']) && ($data['community_board_enabled'] === 'on' || $data['community_board_enabled'] === '1')) ? '1' : '0',
            ];


            $this->settingsRepo->saveSettings($settingsToSave);

            // Protokollierung - logge nur geänderte Werte
            $changedDetails = [];
            foreach ($settingsToSave as $key => $newValue) {
                $oldValue = $oldSettings[$key];
                
                // Konvertiere boolesche Werte für den Vergleich
                if ($key === 'maintenance_mode' || $key === 'ical_enabled' || $key === 'community_board_enabled') { // NEU: community_board_enabled
                    $newValueForCompare = $newValue === '1'; // bool
                } else if (is_numeric($newValue)) {
                    $newValueForCompare = (int)$newValue;
                } else {
                    $newValueForCompare = $newValue;
                }

                if ($newValueForCompare != $oldValue) {
                    // Spezielle Behandlung für Logo/Favicon, um nur "geändert" statt Pfad zu loggen
                    if ($key === 'site_logo_path' || $key === 'site_favicon_path') {
                        if ($newValue === null && $oldValue !== null) $changedDetails[$key] = 'entfernt';
                        elseif ($newValue !== null && $oldValue === null) $changedDetails[$key] = 'hinzugefügt';
                        elseif ($newValue !== $oldValue) $changedDetails[$key] = 'geändert';
                    } else {
                        $changedDetails[$key] = ['old' => $oldValue, 'new' => $newValueForCompare];
                    }
                }
            }
            $changedDetails = array_filter($changedDetails);


            if (!empty($changedDetails)) {
                AuditLogger::log(
                    'update_settings',
                    'system',
                    null,
                    $changedDetails
                );
            }

            // Cache in Utils löschen
            Utils::clearSettingsCache();

            echo json_encode([
                'success' => true,
                'message' => 'Einstellungen erfolgreich gespeichert.',
                'data' => [
                    'site_logo_path' => $logoPath,
                    'site_favicon_path' => $faviconPath,
                    'default_theme' => $defaultTheme
                ]
            ]);

        } catch (Exception $e) {
            $statusCode = $e->getCode();
            if (!is_int($statusCode) || $statusCode < 400 || $statusCode > 599) {
                $statusCode = 400;
            }
            http_response_code($statusCode);
            error_log("Settings save error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * NEU: API-Endpunkt zum Leeren des Caches.
     */
    public function clearCacheApi()
    {
        Security::requireRole('admin');
        header('Content-Type: application/json');

        try {
            // CSRF-Token aus dem Header holen (von apiFetch gesendet)
            Security::verifyCsrfToken(); 

            // Führe die Cache-Löschung durch
            Utils::clearSettingsCache();
            
            // Protokolliere die Aktion
            AuditLogger::log(
                'clear_cache',
                'system',
                null,
                ['cache_type' => 'settings']
            );

            echo json_encode([
                'success' => true,
                'message' => 'Anwendungs-Cache (Einstellungen) wurde erfolgreich geleert.'
            ]);

        } catch (Exception $e) {
            http_response_code(str_contains($e->getMessage(), 'CSRF') ? 403 : 500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
}