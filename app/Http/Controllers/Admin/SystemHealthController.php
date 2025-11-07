<?php
// app/Http/Controllers/Admin/SystemHealthController.php

// MODIFIZIERT:
// 1. SystemHealthService importiert und im Konstruktor instanziiert.
// 2. Alle privaten Logik-Methoden (checkDbStatus, checkExtensions, checkDirectories) wurden entfernt.
// 3. index() ruft jetzt $this->healthService->performSystemChecks() und
//    $this->healthService->getSystemInfo() auf, um die Daten zu laden.
// 4. Die Datenstruktur, die an die View übergeben wird, wurde angepasst,
//    um dem neuen Service-Format zu entsprechen.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Utils;
use App\Core\Database;
use App\Services\SystemHealthService; // NEU: Service importieren

class SystemHealthController
{
    private SystemHealthService $healthService; // NEU

    public function __construct()
    {
        Security::requireRole('admin');
        $this->healthService = new SystemHealthService(); // NEU
    }

    /**
     * Zeigt die System-Status-Seite an.
     * MODIFIZIERT: Ruft Daten vom SystemHealthService ab.
     */
    public function index()
    {
        global $config;
        $config = Database::getConfig();
        $page_title = 'System-Status';
        $body_class = 'admin-dashboard-body';

        // NEU: Daten vom Service abrufen
        $systemInfo = $this->healthService->getSystemInfo();
        $systemChecks = $this->healthService->performSystemChecks();

        // Daten für die View aufbereiten
        $data = [
            'phpVersion' => $systemInfo['php'],
            'serverSoftware' => $systemInfo['webserver'],
            'dbStatus' => $systemChecks['database'], // 'database' enthält jetzt [status, message, tooltip]
            'extensions' => [], // Wird unten gefüllt
            'directoryStatus' => [], // Wird unten gefüllt
            'settings' => Utils::getSettings() // Für den Wartungsmodus-Status
        ];

        // Daten aus den Checks extrahieren, damit die View (system_health.php)
        // weiterhin funktioniert, ohne die View ändern zu müssen.
        foreach ($systemChecks as $key => $check) {
            if (str_starts_with($key, 'ext_')) {
                $extName = str_replace('ext_', '', $key);
                $data['extensions'][$extName] = $check['status']; // true oder false
            }
            if (str_starts_with($key, 'uploads/') || $key === 'cache') {
                 $data['directoryStatus'][$key] = [
                    'status' => $check['status'] ? 'ok' : 'error',
                    'message' => $check['message']
                 ];
            }
        }

        // KORREKTUR: View direkt laden
        include_once dirname(__DIR__, 4) . '/pages/admin/system_health.php';
    }

    /**
     * VERALTET: Alle privaten Check-Methoden wurden entfernt.
     * Die Logik befindet sich jetzt im SystemHealthService.
     */
    // private function checkDbStatus(): array { ... } // (ENTFERNT)
    // private function checkExtensions(): array { ... } // (ENTFERNT)
    // private function checkDirectories(): array { ... } // (ENTFERNT)
}