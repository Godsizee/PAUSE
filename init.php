<?php
// init.php

// Include Parsedown library (hat keine App\ Abhängigkeiten)
require_once __DIR__ . '/libs/Parsedown.php';

// 1. Session starten (MUSS vor JEDER anderen Ausgabe erfolgen)
// Setzt sicherere Session-Optionen
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
// Stellt sicher, dass die Session nicht automatisch gestartet wird, falls doch schon geschehen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// 2. Autoloader für alle Klassen im 'App' Namespace (MUSS VOR der Nutzung von App\ Klassen kommen)
spl_autoload_register(function ($class) {
    // Projekt-spezifischer Namespace-Präfix
    $prefix = 'App\\';

    // Basisverzeichnis für den Namespace-Präfix
    // __DIR__ ist das Verzeichnis der init.php Datei (PAUSE/)
    $base_dir = __DIR__ . '/app/'; // Sollte C:\xampp\htdocs\files\PAUSE\app\ sein

    // Prüft, ob die Klasse den Präfix verwendet
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // Nein, zum nächsten registrierten Autoloader wechseln
        return;
    }

    // Holt den relativen Klassennamen
    $relative_class = substr($class, $len);

    // Ersetzt den Namespace-Präfix mit dem Basisverzeichnis,
    // ersetzt Namespace-Trenner mit Verzeichnis-Trennern,
    // und hängt .php an
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // Wenn die Datei existiert, lade sie
    if (file_exists($file)) {
        require $file;
    }
});

// 3. CSRF-Token generieren oder holen (Session ist bereits aktiv)
// Diese Funktion nutzt jetzt den Autoloader, um App\Core\Security zu laden.
if (session_status() === PHP_SESSION_ACTIVE) {
    \App\Core\Security::getCsrfToken();
} else {
    // Optional: Log, dass keine Session aktiv war für CSRF
    error_log("Session not active when trying to get CSRF token in init.php");
}


// 4. Konfiguration und Datenbankverbindung laden
// Globale Variable $config für leichten Zugriff in Views etc.
global $config;
try {
    $config = App\Core\Database::getConfig();
    // Stelle sicher, dass die DB-Instanz nur geholt wird, wenn nötig,
    // aber hier ist es ok, um die Verbindung früh zu testen.
    $pdo = App\Core\Database::getInstance();
} catch (RuntimeException $e) {
    // Fängt den Fehler ab, wenn die DB-Verbindung fehlschlägt
    // Zeigt eine benutzerfreundliche Fehlerseite an
    http_response_code(503); // Service Unavailable
    // Fetteres Error-Handling wäre besser (eigene Fehlerseite laden)
    die("Fehler: Die Datenbankverbindung konnte nicht hergestellt werden. Bitte überprüfen Sie die Konfiguration oder versuchen Sie es später erneut.");
} catch (Exception $e) { // Fange generische Exceptions beim Laden ab
    http_response_code(500);
    die("Ein kritischer Initialisierungsfehler ist aufgetreten: " . $e->getMessage());
}


// 5. NEU: Wartungsmodus-Prüfung
// Definiert eine Konstante, falls der Zugriff gesperrt werden soll.
try {
    $settings = \App\Core\Utils::getSettings();

    if ($settings['maintenance_mode'] === true) {
        $userRole = $_SESSION['user_role'] ?? 'guest';
        $allowedRoles = ['admin', 'planer']; // Diese Rollen dürfen immer rein
        $userIsAllowed = in_array($userRole, $allowedRoles);

        $userIP = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        
        // KORRIGIERT: Lese Whitelist aus $settings (DB) statt $config (Datei)
        $ipWhitelistString = $settings['maintenance_whitelist_ips'] ?? '';
        // Wandle den String in ein Array um, entferne Leerzeichen
        $ipWhitelist = array_map('trim', explode(',', $ipWhitelistString));
        // Entferne leere Einträge
        $ipWhitelist = array_filter($ipWhitelist); 

        $ipIsAllowed = in_array($userIP, $ipWhitelist);

        // Sperre den Benutzer, wenn er KEINE erlaubte Rolle hat UND seine IP NICHT auf der Whitelist ist
        if (!$userIsAllowed && !$ipIsAllowed) {
            
            // Ausnahme: Login-Seiten müssen erreichbar bleiben, damit Admins sich einloggen können
            $request_uri = $_GET['url'] ?? '/';
            $request_path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
            $allowedPaths = ['login', 'login/process']; // Routen, die immer funktionieren

            if (!in_array($request_path, $allowedPaths)) {
                // Setze die globale Konstante. index.php wird darauf reagieren.
                define('MAINTENANCE_MODE_ACTIVE', true);
            }
        }
    }
} catch (Exception $e) {
    // Kritischer Fehler beim Laden der Einstellungen
    http_response_code(500);
    die("Fehler beim Prüfen des Wartungsstatus: " . $e->getMessage());
}