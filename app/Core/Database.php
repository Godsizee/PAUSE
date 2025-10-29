<?php
// app/Core/Database.php

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton-Klasse für die Datenbankverbindung.
 * Stellt sicher, dass nur eine PDO-Instanz pro Anfrage erstellt wird.
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    // Private constructor and clone method to prevent multiple instances.
    private function __construct() {}
    private function __clone() {}

    /**
     * Loads the database configuration from the config file.
     * @return array The configuration array.
     */
    public static function getConfig(): array
    {
        if (empty(self::$config)) {
            self::$config = require __DIR__ . '/../../config/database_access.php';
        }
        return self::$config;
    }

    /**
     * Establishes and returns the single PDO database connection instance.
     * @return PDO The PDO instance.
     * @throws RuntimeException If the database connection fails.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = self::getConfig();
            
            $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset={$config['db_charset']}";

            // PDO connection options.
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // WICHTIGE ÄNDERUNG:
                // Das Setzen von EMULATE_PREPARES auf 'true' umgeht einen bekannten Bug in einigen
                // PDO-MySQL-Treibern, der den Fehler 'SQLSTATE[HY093]: Invalid parameter number'
                // verursacht, obwohl der PHP-Code und die SQL-Syntax korrekt sind.
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];

            try {
                self::$instance = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
            } catch (PDOException $e) {
                // Prevents leaking sensitive connection details in a production environment.
                error_log("Database connection error: " . $e->getMessage());
                throw new RuntimeException("Database connection could not be established.");
            }
        }

        return self::$instance;
    }
}