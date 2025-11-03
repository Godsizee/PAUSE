<?php
// app/Http/Controllers/Admin/SystemHealthController.php
// KORRIGIERT: Utils::renderView() entfernt und durch manuelles Laden der View (include_once) ersetzt.

namespace App\Http\Controllers\Admin;

use App\Core\Security;
use App\Core\Utils;
use App\Core\Database;

class SystemHealthController
{
    public function __construct()
    {
        Security::requireRole('admin');
    }

    /**
     * Zeigt die System-Status-Seite an.
     */
    public function index()
    {
        // KORREKTUR: Globale Variablen setzen, die vom Header benötigt werden
        global $config;
        $config = Database::getConfig();
        $page_title = 'System-Status';
        $body_class = 'admin-dashboard-body';

        // $data wird an die View übergeben
        $data = [
            'phpVersion' => phpversion(),
            'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'dbStatus' => $this->checkDbStatus(),
            'extensions' => $this->checkExtensions(),
            'directoryStatus' => $this->checkDirectories(),
            'settings' => Utils::getSettings() // Für den Wartungsmodus-Status
        ];

        // KORREKTUR: View direkt laden (diese lädt dann Header/Footer)
        include_once dirname(__DIR__, 4) . '/pages/admin/system_health.php';
    }

    /**
     * Prüft die Datenbankverbindung.
     */
    private function checkDbStatus(): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->query("SELECT 1");
            return ['status' => 'ok', 'message' => 'Verbunden'];
        } catch (\PDOException $e) {
            // Zeige nicht die volle Fehlermeldung, um DB-Details zu schützen
            return ['status' => 'error', 'message' => 'Nicht verbunden'];
        }
    }

    /**
     * Prüft wichtige PHP-Extensions.
     */
    private function checkExtensions(): array
    {
        $required = ['pdo_mysql', 'openssl', 'gd', 'mbstring', 'json', 'intl'];
        $status = [];

        foreach ($required as $ext) {
            $status[$ext] = extension_loaded($ext);
        }
        return $status;
    }

    /**
     * Prüft, ob wichtige Verzeichnisse beschreibbar sind.
     */
    private function checkDirectories(): array
    {
        $projectRoot = dirname(__DIR__, 4);
        $dirs = [
            'cache' => $projectRoot . '/cache',
            'uploads/announcements' => $projectRoot . '/public/uploads/announcements',
            'uploads/branding' => $projectRoot . '/public/uploads/branding'
        ];
        
        $status = [];
        foreach ($dirs as $name => $path) {
            $isDir = is_dir($path);
            $isWritable = $isDir && is_writable($path);
            
            if (!$isDir) {
                $status[$name] = ['status' => 'error', 'message' => 'Verzeichnis existiert nicht'];
            } elseif (!$isWritable) {
                $status[$name] = ['status' => 'error', 'message' => 'Nicht beschreibbar'];
            } else {
                $status[$name] = ['status' => 'ok', 'message' => 'OK'];
            }
        }
        return $status;
    }
}