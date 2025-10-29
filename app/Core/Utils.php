<?php

namespace App\Core;

use App\Repositories\SettingsRepository; // NEU: Importiere SettingsRepository
use Exception; // NEU: Importiere Exception

class Utils
{
    private static ?array $settingsCache = null; // NEU: Cache für Einstellungen

    /**
     * Generiert eine saubere, SEO-freundliche URL, die vom .htaccess-Router verarbeitet wird.
     * @param string $path Der interne Pfad (z.B. 'profil' oder 'admin/users').
     * @return string Die vollständige, funktionierende URL.
     */
    public static function url(string $path): string
    {
        // Holt die Basis-URL aus der Konfiguration.
        // Diese sollte auf den öffentlichen Ordner zeigen, z.B. '/files/PAUSE/public'.
        $base_url = rtrim(Database::getConfig()['base_url'], '/');

        // Wenn der Pfad leer ist oder nur aus einem / besteht, verlinke zur Startseite.
        if (empty($path) || $path === '/') {
            return $base_url . '/';
        }

        // Hängt den internen Pfad an die Basis-URL an.
        // z.B. wird aus 'login/process' -> '/files/PAUSE/public/login/process'
        return $base_url . '/' . ltrim($path, '/');
    }

    /**
     * Holt die Einstellungen.
     * Versucht zuerst, aus der DB zu laden, und verwendet Standardwerte als Fallback.
     * Ergebnisse werden für die Dauer des Requests zwischengespeichert.
     * @return array
     */
    public static function getSettings(): array
    {
        // Prüfe, ob Einstellungen bereits im Cache liegen
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        // 1. Definiere Standardwerte
        $defaultSettings = [
            'site_title' => 'PAUSE Portal',
            'maintenance_mode' => '0', // Standardwert für Wartungsmodus
            'maintenance_message' => 'Die Anwendung wird gerade gewartet. Bitte versuchen Sie es später erneut.',
            'maintenance_whitelist_ips' => "127.0.0.1, ::1", // IP Whitelist als String
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
            'community_board_enabled' => '1', // NEU: Standardmäßig aktiviert
        ];

        // 2. Lade Einstellungen aus der Datenbank
        try {
            $settingsRepo = new SettingsRepository();
            $dbSettings = $settingsRepo->loadSettings();
        } catch (Exception $e) {
            // Fährt fort, wenn DB-Tabelle (z.B. bei Erstinstallation) noch nicht existiert
            error_log("Hinweis: Konnte Einstellungen nicht aus der DB laden, verwende Standardwerte. Fehler: " . $e->getMessage());
            $dbSettings = [];
        }

        // 3. Überschreibe Standardwerte mit den Werten aus der Datenbank
        // (DB hat Vorrang)
        $finalSettings = array_merge($defaultSettings, $dbSettings);

        // 4. Typkonvertierung (DB speichert alles als String)
        $finalSettings['maintenance_mode'] = (($finalSettings['maintenance_mode'] ?? '0') === '1' || ($finalSettings['maintenance_mode'] ?? false) === true);
        $finalSettings['default_start_hour'] = (int)($finalSettings['default_start_hour'] ?? 1);
        $finalSettings['default_end_hour'] = (int)($finalSettings['default_end_hour'] ?? 10);
        $finalSettings['max_login_attempts'] = (int)($finalSettings['max_login_attempts'] ?? 5);
        $finalSettings['lockout_minutes'] = (int)($finalSettings['lockout_minutes'] ?? 15);
        $finalSettings['ical_enabled'] = (($finalSettings['ical_enabled'] ?? '1') === '1' || ($finalSettings['ical_enabled'] ?? false) === true);
        $finalSettings['ical_weeks_future'] = (int)($finalSettings['ical_weeks_future'] ?? 8);
        // NEU: Konvertierung für Community Board
        $finalSettings['community_board_enabled'] = (($finalSettings['community_board_enabled'] ?? '1') === '1' || ($finalSettings['community_board_enabled'] ?? false) === true);
        // maintenance_whitelist_ips bleibt ein String

        // 5. Im Cache speichern und zurückgeben
        self::$settingsCache = $finalSettings;
        return $finalSettings;
    }

    /**
     * NEU: Löscht den Einstellungs-Cache.
     * Wird nach dem Speichern von Einstellungen aufgerufen.
     */
    public static function clearSettingsCache(): void
    {
        self::$settingsCache = null;
    }
}