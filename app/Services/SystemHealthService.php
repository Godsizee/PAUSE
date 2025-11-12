<?php
namespace App\Services;
use App\Core\Database;
use App\Core\Utils;
use PDO;
use Exception;
use PDOException; 
class SystemHealthService
{
    private string $projectRoot;
    private string $publicPath;
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->publicPath = $this->projectRoot . '/public/';
    }
    public function performSystemChecks(): array
    {
        $checks = [];
        $dbStatus = $this->checkDbStatus();
        $checks['database'] = [
            'label' => 'Datenbank-Verbindung',
            'status' => $dbStatus['status'] === 'ok',
            'message' => $dbStatus['message'],
            'tooltip' => 'Die Verbindung zur MySQL-Datenbank.'
        ];
        $checks['config_file'] = [
            'label' => 'Konfigurationsdatei',
            'status' => true,
            'message' => 'Geladen', 
            'tooltip' => 'Datei: database_access.php'
        ];
        $extensions = $this->checkExtensions();
        foreach ($extensions as $ext => $status) {
             $checks['ext_' . $ext] = [
                'label' => 'PHP Extension: ' . $ext,
                'status' => $status,
                'message' => $status ? 'OK' : 'Fehlt!',
                'tooltip' => $status ? 'Erweiterung ist geladen.' : 'Erforderlich für Kernfunktionen.'
            ];
        }
        $directories = $this->checkDirectories();
        foreach ($directories as $name => $dir) {
             $checks[$name] = [
                'label' => 'Verzeichnis: ' . $name,
                'status' => $dir['status'] === 'ok',
                'message' => $dir['message'],
                'tooltip' => 'Pfad: ' . $dir['path_relative']
             ];
        }
        return $checks;
    }
    public function checkDbStatus(): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->query("SELECT 1");
            return ['status' => 'ok', 'message' => 'Verbunden (v' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ')'];
        } catch (PDOException $e) {
            return ['status' => 'error', 'message' => 'Nicht verbunden'];
        }
    }
    public function checkExtensions(): array
    {
        $required = ['pdo_mysql', 'openssl', 'gd', 'mbstring', 'json', 'intl'];
        $status = [];
        foreach ($required as $ext) {
            $status[$ext] = extension_loaded($ext);
        }
        return $status;
    }
    public function checkDirectories(): array
    {
        $dirs = [
            'cache' => $this->projectRoot . '/cache',
            'uploads/announcements' => $this->publicPath . 'uploads/announcements',
            'uploads/branding' => $this->publicPath . 'uploads/branding'
        ];
        $status = [];
        foreach ($dirs as $name => $path) {
            $pathRelative = str_replace($this->projectRoot, '', $path); 
            $pathRelative = str_replace($this->publicPath, 'public/', $pathRelative);
            $pathRelative = str_replace('//', '/', $pathRelative);
            if (!is_dir($path)) {
                if (!@mkdir($path, 0775, true)) {
                     $status[$name] = [
                        'status' => 'error', 
                        'message' => 'Fehlt & Erstellen fehlgeschlagen',
                        'path_relative' => $pathRelative
                     ];
                } else {
                     $status[$name] = [
                        'status' => 'ok', 
                        'message' => 'OK (Erstellt)',
                        'path_relative' => $pathRelative
                     ];
                }
            } else {
                $testFile = rtrim($path, '/') . '/write_test.tmp';
                if (@file_put_contents($testFile, 'test') !== false) {
                    @unlink($testFile);
                    $status[$name] = [
                        'status' => 'ok', 
                        'message' => 'Beschreibbar',
                        'path_relative' => $pathRelative
                    ];
                } else {
                    $status[$name] = [
                        'status' => 'error', 
                        'message' => 'Nicht beschreibbar!',
                        'path_relative' => $pathRelative
                    ];
                }
            }
        }
        return $status;
    }
    public function getSystemInfo(): array
    {
         try {
            $pdo = Database::getInstance();
            $dbVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
         } catch (Exception $e) {
            $dbVersion = 'N/A';
         }
         return [
            'php' => phpversion(),
            'db' => $dbVersion,
            'webserver' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'
         ];
    }
}