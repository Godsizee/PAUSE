<?php
// app/Http/Controllers/Admin/SettingsController.php

// MODIFIZIZERT:
// 1. FileUploadService importiert und im Konstruktor injiziert.
// 2. Die private Methode handleFileUpload() wurde entfernt.
// 3. Die save()-Methode verwendet jetzt $this->fileUploadService->handleUpload()
//    und $this->fileUploadService->deleteFile().
// 4. Die Logik zum Entfernen von Dateien wurde in den Callback des Traits verschoben.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Core\Cache;
use App\Repositories\SettingsRepository;
use App\Services\AuditLogger;
use App\Http\Traits\ApiHandlerTrait;
use App\Services\FileUploadService; // NEU: FileUploadService importieren
use Exception;
use PDO;

class SettingsController
{
    use ApiHandlerTrait;

    private SettingsRepository $settingsRepo;
    private FileUploadService $fileUploadService; // NEU

    // VERALTET: $uploadDir wird nicht mehr benötigt, da der Service den Pfad kennt.
    // private string $uploadDir; 

    public function __construct()
    {
        $this->settingsRepo = new SettingsRepository();
        $this->fileUploadService = new FileUploadService(); // NEU: Service instanziieren
        // $this->uploadDir = ... (ENTFERNT)
    }

    /**
     * Zeigt die Hauptseite für die Anwendungseinstellungen an.
     * MODIFIZIERT: Erstellt das Verzeichnis nicht mehr hier, der Service macht das bei Bedarf.
     */
    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();

        $page_title = 'Anwendungs-Einstellungen';
        $body_class = 'admin-dashboard-body';

        $currentSettings = Utils::getSettings();

        // Verzeichnis-Erstellung entfernt - der FileUploadService kümmert sich darum.

        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/admin/settings.php';
    }

    /**
     * VERALTET: Die Methode private function handleFileUpload() wurde entfernt.
     * Die Logik befindet sich jetzt im FileUploadService.
     */
    // private function handleFileUpload(...) { ... } // (ENTFERNT)


    /**
     * API: Speichert die Anwendungseinstellungen.
     * MODIFIZIERT: Nutzt jetzt FileUploadService.
     */
    public function save()
    {
        $this->handleApiRequest(function($data) { // $data ist $_POST

            $oldSettings = Utils::getSettings();
            $logoPath = $oldSettings['site_logo_path'] ?? null;
            $faviconPath = $oldSettings['site_favicon_path'] ?? null;

            // --- Datei-Uploads / Löschungen ---
            
            // 1. Logo verarbeiten
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                // Wenn ein neues Logo hochgeladen wird, altes zuerst löschen
                $this->fileUploadService->deleteFile($logoPath);
                // Neues Logo hochladen und Pfad speichern
                $logoPath = $this->fileUploadService->handleUpload(
                    'site_logo',
                    'branding',
                    ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/gif' => 'gif']
                );
            } elseif (isset($data['remove_site_logo']) && $data['remove_site_logo'] === '1') {
                // Logo entfernen (ohne neues hochzuladen)
                $this->fileUploadService->deleteFile($logoPath);
                $logoPath = null;
            }
            // (Wenn nichts passiert, bleibt $logoPath der alte Pfad)

            // 2. Favicon verarbeiten
            if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $this->fileUploadService->deleteFile($faviconPath);
                $faviconPath = $this->fileUploadService->handleUpload(
                    'site_favicon',
                    'branding',
                    ['image/x-icon' => 'ico', 'image/png' => 'png', 'image/svg+xml' => 'svg']
                );
            } elseif (isset($data['remove_site_favicon']) && $data['remove_site_favicon'] === '1') {
                $this->fileUploadService->deleteFile($faviconPath);
                $faviconPath = null;
            }

            // --- Validierungen (unverändert) ---
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
            $whitelistIPs = $data['maintenance_whitelist_ips'] ?? '';
            $whitelistIPs = preg_replace('/[^0-9a-fA-F:.,\s]/', '', $whitelistIPs);
            $whitelistIPs = preg_replace('/[\s,]+/', ',', $whitelistIPs);
            $whitelistIPs = trim($whitelistIPs, ',');


            // Aufbereitete Einstellungen für das Repository
            $settingsToSave = [
                'site_title' => trim($data['site_title'] ?? 'PAUSE Portal'),
                'maintenance_mode' => (isset($data['maintenance_mode']) && ($data['maintenance_mode'] === 'on' || $data['maintenance_mode'] === '1')) ? '1' : '0',
                'maintenance_message' => trim($data['maintenance_message'] ?? ''),
                'maintenance_whitelist_ips' => $whitelistIPs,
                'default_start_hour' => $startHour,
                'default_end_hour' => $endHour,
                'max_login_attempts' => $maxAttempts,
                'lockout_minutes' => $lockoutMinutes,
                'site_logo_path' => $logoPath, // NEU: Der aktualisierte Pfad
                'site_favicon_path' => $faviconPath, // NEU: Der aktualisierte Pfad
                'default_theme' => $defaultTheme,
                'ical_enabled' => (isset($data['ical_enabled']) && ($data['ical_enabled'] === 'on' || $data['ical_enabled'] === '1')) ? '1' : '0',
                'ical_weeks_future' => $icalWeeksFuture,
                'pdf_footer_text' => trim($data['pdf_footer_text'] ?? ''),
                'community_board_enabled' => (isset($data['community_board_enabled']) && ($data['community_board_enabled'] === 'on' || $data['community_board_enabled'] === '1')) ? '1' : '0',
            ];

            // Speichern
            $this->settingsRepo->saveSettings($settingsToSave);

            // Cache in Utils löschen (wichtig!)
            Utils::clearSettingsCache();

            // Protokollierung - logge nur geänderte Werte
            $changedDetails = [];
            foreach ($settingsToSave as $key => $newValue) {
                $oldValue = $oldSettings[$key];
                
                if (is_bool($oldValue)) {
                     $newValueForCompare = $newValue === '1';
                } else if (is_numeric($newValue)) {
                     $newValueForCompare = (int)$newValue;
                } else {
                     $newValueForCompare = $newValue;
                }

                if ($newValueForCompare != $oldValue) {
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


            // Rückgabe-Array für den ApiHandlerTrait
            return [
                'json_response' => [
                    'success' => true,
                    'message' => 'Einstellungen erfolgreich gespeichert.',
                    'data' => [ 
                        'site_logo_path' => $logoPath,
                        'site_favicon_path' => $faviconPath,
                        'default_theme' => $defaultTheme
                    ]
                ],
                'log_action' => 'update_settings',
                'log_target_type' => 'system',
                'log_details' => $changedDetails ?: null
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }


    /**
     * API-Endpunkt zum Leeren des Caches.
     * (Unverändert)
     */
    public function clearCacheApi()
    {
        $this->handleApiRequest(function($data) {

            Utils::clearSettingsCache();
            $settingsMessage = 'Einstellungen-Cache geleert.';

            $appCacheResult = Cache::clearAll();
            $appMessage = $appCacheResult['message'];

            if (!$appCacheResult['success']) {
                throw new Exception("Fehler beim Leeren des App-Caches: " . $appMessage, 500);
            }

            return [
                'json_response' => [
                    'success' => true,
                    'message' => "Erfolgreich! $settingsMessage. $appMessage"
                ],
                'log_action' => 'clear_cache',
                'log_target_type' => 'system',
                'log_details' => ['cache_type' => 'all_application_caches']
            ];

        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }
}