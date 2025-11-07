<?php
// app/Services/SystemHealthService.php

namespace App\Services;

use App\Core\Database;
use App\Core\Utils;
use PDO;
use Exception;
use PDOException; // Importiert für spezifisches DB-Error-Handling

/**
 * Service-Klasse zur Kapselung von System-Status- und Gesundheitsprüfungen.
 * Stellt wiederverwendbare Methoden für Controller (AdminDashboard, SystemHealth) bereit.
 */
class SystemHealthService
{
    private string $projectRoot;
    private string $publicPath;

    public function __construct()
    {
        // Geht 2 Ebenen vom 'app/Services' Verzeichnis hoch zum Projektstamm
        $this->projectRoot = dirname(__DIR__, 2);
        $this->publicPath = $this->projectRoot . '/public/';
    }

    /**
     * Führt eine umfassende Prüfung aller wichtigen Systemkomponenten durch.
     * (Kombiniert die Logik aus AdminDashboardController und SystemHealthController)
     *
     * @return array Ein Array von Prüfergebnissen.
     */
    public function performSystemChecks(): array
    {
        $checks = [];

        // 1. Datenbankverbindung
        $dbStatus = $this->checkDbStatus();
        $checks['database'] = [
            'label' => 'Datenbank-Verbindung',
            'status' => $dbStatus['status'] === 'ok',
            'message' => $dbStatus['message'],
            'tooltip' => 'Die Verbindung zur MySQL-Datenbank.'
        ];

        // 2. Konfigurationsdatei-Check
        $checks['config_file'] = [
            'label' => 'Konfigurationsdatei',
            'status' => true,
            'message' => 'Geladen', // (Wird in init.php geladen)
            'tooltip' => 'Datei: database_access.php'
        ];
        
        // 3. PHP Extensions
        $extensions = $this->checkExtensions();
        foreach ($extensions as $ext => $status) {
             $checks['ext_' . $ext] = [
                'label' => 'PHP Extension: ' . $ext,
                'status' => $status,
                'message' => $status ? 'OK' : 'Fehlt!',
                'tooltip' => $status ? 'Erweiterung ist geladen.' : 'Erforderlich für Kernfunktionen.'
            ];
        }

        // 4. Verzeichnis-Berechtigungen
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

    /**
     * Prüft die Datenbankverbindung.
     *
     * @return array ['status' => 'ok'|'error', 'message' => string]
     */
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

    /**
     * Prüft wichtige PHP-Extensions.
     *
     * @return array [ext_name => bool (geladen?)]
     */
    public function checkExtensions(): array
    {
        // Kombinierte Liste der erforderlichen Erweiterungen
        $required = ['pdo_mysql', 'openssl', 'gd', 'mbstring', 'json', 'intl'];
        $status = [];

        foreach ($required as $ext) {
            $status[$ext] = extension_loaded($ext);
        }
        return $status;
    }

    /**
     * Prüft, ob wichtige Verzeichnisse existieren und beschreibbar sind.
     * Versucht, Verzeichnisse zu erstellen, falls sie fehlen.
     *
     * @return array Status-Array für jedes Verzeichnis.
     */
    public function checkDirectories(): array
    {
        $dirs = [
            'cache' => $this->projectRoot . '/cache',
            'uploads/announcements' => $this->publicPath . 'uploads/announcements',
            'uploads/branding' => $this->publicPath . 'uploads/branding'
        ];
        
        $status = [];
        foreach ($dirs as $name => $path) {
            
            $pathRelative = str_replace($this->projectRoot, '', $path); // Für Tooltip
            $pathRelative = str_replace($this->publicPath, 'public/', $pathRelative);
            $pathRelative = str_replace('//', '/', $pathRelative);

            if (!is_dir($path)) {
                // Versuche, das Verzeichnis zu erstellen
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
                // Verzeichnis existiert, teste Schreibzugriff
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

    /**
     * Holt grundlegende System-Informationen.
     *
     * @return array
     */

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