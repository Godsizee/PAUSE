<?php
namespace App\Core;
use PDO;
use PDOException;
use RuntimeException;
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];
    private function __construct() {}
    private function __clone() {}
    public static function getConfig(): array
    {
        if (empty(self::$config)) {
            self::$config = require __DIR__ . '/../../config/database_access.php';
        }
        return self::$config;
    }
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = self::getConfig();
            $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset={$config['db_charset']}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];
            try {
                self::$instance = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
            } catch (PDOException $e) {
                error_log("Database connection error: " . $e->getMessage());
                throw new RuntimeException("Database connection could not be established.");
            }
        }
        return self::$instance;
    }
}