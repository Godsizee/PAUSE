<?php
require_once __DIR__ . '/libs/Parsedown.php';
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/app/'; 
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
if (session_status() === PHP_SESSION_ACTIVE) {
    \App\Core\Security::getCsrfToken();
} else {
    error_log("Session not active when trying to get CSRF token in init.php");
}
global $config;
try {
    $config = App\Core\Database::getConfig();
    $pdo = App\Core\Database::getInstance();
} catch (RuntimeException $e) {
    http_response_code(503); 
    error_log("Schwerwiegender DB-Fehler in init.php: " . $e->getMessage());
    $maintenance_message = "Fehler: Die Datenbankverbindung konnte nicht hergestellt werden. \nBitte versuchen Sie es zu einem späteren Zeitpunkt erneut.";
    require_once __DIR__ . '/pages/partials/header.php';
    require_once __DIR__ . '/pages/errors/503.php';
    require_once __DIR__ . '/pages/partials/footer.php';
    exit;
} catch (Exception $e) { 
    http_response_code(500);
    die("Ein kritischer Initialisierungsfehler ist aufgetreten: " . $e->getMessage());
}
try {
    $settings = \App\Core\Utils::getSettings();
    if ($settings['maintenance_mode'] === true) {
        $userRole = $_SESSION['user_role'] ?? 'guest';
        $allowedRoles = ['admin', 'planer']; 
        $userIsAllowed = in_array($userRole, $allowedRoles);
        $userIP = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $ipWhitelistString = $settings['maintenance_whitelist_ips'] ?? '';
        $ipWhitelist = array_map('trim', explode(',', $ipWhitelistString));
        $ipWhitelist = array_filter($ipWhitelist); 
        $ipIsAllowed = in_array($userIP, $ipWhitelist);
        if (!$userIsAllowed && !$ipIsAllowed) {
            $request_uri = $_GET['url'] ?? '/';
            $request_path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
            $allowedPaths = ['login', 'login/process']; 
            if (!in_array($request_path, $allowedPaths)) {
                define('MAINTENANCE_MODE_ACTIVE', true);
            }
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    die("Fehler beim Prüfen des Wartungsstatus: " . $e->getMessage());
}