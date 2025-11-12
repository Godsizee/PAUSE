<?php
namespace App\Http\Controllers\Admin;
use App\Core\Security;
use App\Core\Database;
use App\Core\Utils;
use App\Core\Cache;
use App\Repositories\SettingsRepository;
use App\Services\AuditLogger;
use Exception;
use PDO;
use App\Http\Traits\ApiHandlerTrait;

class SettingsController
{
    use ApiHandlerTrait;

    private SettingsRepository $settingsRepo;
    private string $uploadDir;
    public function __construct()
    {
        $this->settingsRepo = new SettingsRepository();
        $this->uploadDir = dirname(__DIR__, 4) . '/public/uploads/branding/';
    }

    public function index()
    {
        Security::requireRole('admin');
        global $config;
        $config = Database::getConfig();
        $page_title = 'Anwendungs-Einstellungen';
        $body_class = 'admin-dashboard-body';
        $currentSettings = Utils::getSettings();
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }
        Security::getCsrfToken();
        include_once dirname(__DIR__, 4) . '/pages/admin/settings.php';
    }

    // KORREKTUR: Akzeptiert jetzt $files ($_FILES) und $postData ($_POST) vom Trait
    private function handleFileUpload(string $fileKey, array $allowedMimes, ?string $currentPath, array $files, array $postData): ?string
    {
        if (isset($files[$fileKey]) && $files[$fileKey]['error'] === UPLOAD_ERR_OK) {
            $file = $files[$fileKey];
            $fileType = mime_content_type($file['tmp_name']);
            if (!array_key_exists($fileType, $allowedMimes)) {
                throw new Exception("Ungültiger Dateityp für '{$fileKey}'. Erlaubt: " . implode(', ', array_keys($allowedMimes)), 400);
            }
            if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true)) {
                throw new Exception("Upload-Verzeichnis konnte nicht erstellt werden.", 500);
            }
            if (!is_writable($this->uploadDir)) {
                throw new Exception("Upload-Verzeichnis ist nicht beschreibbar.", 500);
            }
            $extension = $allowedMimes[$fileType];
            $fileName = $fileKey . '_' . uniqid() . '.' . $extension;
            $targetPath = $this->uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                if ($currentPath && file_exists(dirname(__DIR__, 4) . '/public/' . $currentPath)) {
                    @unlink(dirname(__DIR__, 4) . '/public/' . $currentPath);
                }
                return 'uploads/branding/' . $fileName;
            } else {
                throw new Exception("Fehler beim Verschieben der hochgeladenen Datei.", 500);
            }
        }

        // KORREKTUR: Verwendet $postData (das $data aus dem Callback ist) statt $_POST
        if (isset($postData['remove_' . $fileKey]) && $postData['remove_' . $fileKey] === '1') {
            if ($currentPath && file_exists(dirname(__DIR__, 4) . '/public/' . $currentPath)) {
                @unlink(dirname(__DIR__, 4) . '/public/' . $currentPath);
            }
            return null;
        }
        return $currentPath;
    }

    public function save()
    {
        // KORREKTUR: Callback akzeptiert jetzt $data ($_POST) und $files ($_FILES)
        $this->handleApiRequest(function($data, $files) {
            $oldSettings = Utils::getSettings();
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
           
            // KORREKTUR: $files und $data werden explizit an handleFileUpload übergeben
            $logoPath = $this->handleFileUpload(
                'site_logo',
                ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/gif' => 'gif'],
                $oldSettings['site_logo_path'] ?? null,
                $files, // <-- Hinzugefügt
                $data // <-- Hinzugefügt
            );
            $faviconPath = $this->handleFileUpload(
                'site_favicon',
                ['image/x-icon' => 'ico', 'image/png' => 'png', 'image/svg+xml' => 'svg'],
                $oldSettings['site_favicon_path'] ?? null,
                $files, // <-- Hinzugefügt
                $data // <-- Hinzugefügt
            );

            $settingsToSave = [
                'site_title' => trim($data['site_title'] ?? 'PAUSE Portal'),
                'maintenance_mode' => (isset($data['maintenance_mode']) && ($data['maintenance_mode'] === 'on' || $data['maintenance_mode'] === '1')) ? '1' : '0',
                'maintenance_message' => trim($data['maintenance_message'] ?? ''),
                'maintenance_whitelist_ips' => $whitelistIPs,
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
                'community_board_enabled' => (isset($data['community_board_enabled']) && ($data['community_board_enabled'] === 'on' || $data['community_board_enabled'] === '1')) ? '1' : '0',
            ];
            $this->settingsRepo->saveSettings($settingsToSave);
            $changedDetails = [];
            foreach ($settingsToSave as $key => $newValue) {
                $oldValue = $oldSettings[$key];
                if ($key === 'maintenance_mode' || $key === 'ical_enabled' || $key === 'community_board_enabled') {
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

            return [
                'log_action' => 'update_settings',
                'log_target_type' => 'system',
                'log_details' => $changedDetails
            ];
        }, [
            'inputType' => 'form',
            'checkRole' => 'admin'
        ]);
    }

    public function clearCacheApi()
    {
        // KORREKTUR: Signatur an Trait angepasst
        $this->handleApiRequest(function($data, $files) {
            Utils::clearSettingsCache();
            $settingsMessage = 'Einstellungen-Cache geleert.';
            $appCacheResult = Cache::clearAll();
            $appMessage = $appCacheResult['message'];
            if (!$appCacheResult['success']) {
                throw new Exception("Fehler beim Leeren des App-Caches: " . $appMessage);
            }

            echo json_encode([
                'success' => true,
                'message' => "Erfolgreich! $settingsMessage. $appMessage"
            ]);
           
            return [
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