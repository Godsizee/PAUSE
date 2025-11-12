<?php
namespace App\Core;
use App\Repositories\SettingsRepository; 
use Exception; 
class Utils
{
    private static ?array $settingsCache = null; 
    public static function url(string $path): string
    {
        $base_url = rtrim(Database::getConfig()['base_url'], '/');
        if (empty($path) || $path === '/') {
            return $base_url . '/';
        }
        return $base_url . '/' . ltrim($path, '/');
    }
    public static function getSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        $defaultSettings = [
            'site_title' => 'PAUSE Portal',
            'maintenance_mode' => '0', 
            'maintenance_message' => 'Die Anwendung wird gerade gewartet. Bitte versuchen Sie es später erneut.',
            'maintenance_whitelist_ips' => "127.0.0.1, ::1", 
            'default_start_hour' => 1,
            'default_end_hour' => 10,
            'max_login_attempts' => 5,
            'lockout_minutes' => 15,
            'site_logo_path' => null,
            'site_favicon_path' => null,
            'default_theme' => 'light',
            'ical_enabled' => '1',
            'ical_weeks_future' => 8,
            'pdf_footer_text' => 'PAUSE Portal - PMI - Ein Produkt des PMI.',
            'community_board_enabled' => '1', 
        ];
        try {
            $settingsRepo = new SettingsRepository();
            $dbSettings = $settingsRepo->loadSettings();
        } catch (Exception $e) {
            error_log("Hinweis: Konnte Einstellungen nicht aus der DB laden, verwende Standardwerte. Fehler: " . $e->getMessage());
            $dbSettings = [];
        }
        $finalSettings = array_merge($defaultSettings, $dbSettings);
        $finalSettings['maintenance_mode'] = (($finalSettings['maintenance_mode'] ?? '0') === '1' || ($finalSettings['maintenance_mode'] ?? false) === true);
        $finalSettings['default_start_hour'] = (int)($finalSettings['default_start_hour'] ?? 1);
        $finalSettings['default_end_hour'] = (int)($finalSettings['default_end_hour'] ?? 10);
        $finalSettings['max_login_attempts'] = (int)($finalSettings['max_login_attempts'] ?? 5);
        $finalSettings['lockout_minutes'] = (int)($finalSettings['lockout_minutes'] ?? 15);
        $finalSettings['ical_enabled'] = (($finalSettings['ical_enabled'] ?? '1') === '1' || ($finalSettings['ical_enabled'] ?? false) === true);
        $finalSettings['ical_weeks_future'] = (int)($finalSettings['ical_weeks_future'] ?? 8);
        $finalSettings['community_board_enabled'] = (($finalSettings['community_board_enabled'] ?? '1') === '1' || ($finalSettings['community_board_enabled'] ?? false) === true);
        self::$settingsCache = $finalSettings;
        return $finalSettings;
    }
    public static function clearSettingsCache(): void
    {
        self::$settingsCache = null;
    }
}