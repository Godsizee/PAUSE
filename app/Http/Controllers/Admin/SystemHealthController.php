<?php
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
    public function index()
    {
        global $config;
        $config = Database::getConfig();
        $page_title = 'System-Status';
        $body_class = 'admin-dashboard-body';
        $data = [
            'phpVersion' => phpversion(),
            'serverSoftware' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'dbStatus' => $this->checkDbStatus(),
            'extensions' => $this->checkExtensions(),
            'directoryStatus' => $this->checkDirectories(),
            'settings' => Utils::getSettings() 
        ];
        include_once dirname(__DIR__, 4) . '/pages/admin/system_health.php';
    }
    private function checkDbStatus(): array
    {
        try {
            $pdo = Database::getInstance();
            $pdo->query("SELECT 1");
            return ['status' => 'ok', 'message' => 'Verbunden'];
        } catch (\PDOException $e) {
            return ['status' => 'error', 'message' => 'Nicht verbunden'];
        }
    }
    private function checkExtensions(): array
    {
        $required = ['pdo_mysql', 'openssl', 'gd', 'mbstring', 'json', 'intl'];
        $status = [];
        foreach ($required as $ext) {
            $status[$ext] = extension_loaded($ext);
        }
        return $status;
    }
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