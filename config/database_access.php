<?php
// config/database_access.php

return [
    'db_host'    => '127.0.0.1', // oder 'localhost'
    'db_port'    => 3306,
    'db_name'    => 'pause_db',
    'db_user'    => 'root',
    'db_pass'    => '', // Standard-Passwort für XAMPP ist leer
    'db_charset' => 'utf8mb4',
    // WICHTIG: Die base_url muss auf den öffentlichen Ordner zeigen!
    'base_url'  => '/files/PAUSE/public',


];