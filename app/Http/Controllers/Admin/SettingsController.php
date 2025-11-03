<?php
// app/Http/Controllers/Admin/SettingsController.php
// NEU ERSTELLT: Basiert auf config/routes.php

namespace App\Http\Controllers\Admin;

use App\Core\Cache;
use App\Core\Security;
use App\Core\Utils;
use App\Repositories\SettingsRepository;
use App\Services\AuditLogger;

class SettingsController
{
    private $settingsRepo;

    public function __construct()
    {
        // Stellt sicher, dass nur Admins auf diese Sektion zugreifen können
        if (!Security::checkUserRole('admin')) {
            Security::redirectWithError('Zugriff verweigert.');
        }
        $this->settingsRepo = new SettingsRepository();
    }

    /**
     * Zeigt die Haupt-Einstellungsseite an.
     */
    public function index()
    {
        $settings = $this->settingsRepo->getAllSettings();
        $csrfToken = Security::getCsrfToken();
        
        // Stellt die Daten für die View bereit
        $data = [
            'settings' => $settings,
            'csrfToken' => $csrfToken
        ];

        // Lädt die View-Datei
        Utils::renderView('admin/settings', $data);
    }

    /**
     * Speichert die allgemeinen Einstellungen (Platzhalter, noch nicht voll implementiert).
     * Diese Route wird von routes.php definiert.
     */
    public function save()
    {
        // Implementierungslogik für das Speichern von Einstellungen
        // z.B. $this->settingsRepo->updateSetting('maintenance_mode', $_POST['maintenance_mode']);
        // ...
        
        // Nach dem Speichern zurückleiten
        Utils::redirect('admin/settings', ['status' => 'saved']);
    }

    /**
     * API-Endpunkt zum Leeren des Caches.
     */
    public function clearCacheApi()
    {
        header('Content-Type: application/json');

        // 1. Sicherheit: Nur POST-Requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Ungültige Methode.']);
            return;
        }

        // 2. Sicherheit: CSRF-Token prüfen
        if (!Security::checkCsrfToken()) {
            echo json_encode(['success' => false, 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.']);
            return;
        }

        // 3. Cache leeren
        $result = Cache::clearAll();

        // 4. Aktion protokollieren
        if ($result['success']) {
            AuditLogger::log('system', 'Cache geleert', 'Admin hat den Anwendungs-Cache geleert.');
        } else {
            AuditLogger::log('system', 'Cache-Fehler', $result['message']);
        }

        // 5. Antwort senden
        echo json_encode($result);
    }
}