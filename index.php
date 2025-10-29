<?php
// index.php

// 1. Initialisiert das Projekt (Session, Autoloader, DB-Verbindung, Wartungsmodus-Check)
require_once __DIR__ . '/init.php';

// 2. NEU: Prüfe, ob der Wartungsmodus den Zugriff blockieren soll
if (defined('MAINTENANCE_MODE_ACTIVE') && MAINTENANCE_MODE_ACTIVE === true) {
    http_response_code(503); // Service Unavailable
    
    // Lade die globalen Einstellungen für die Wartungsmeldung
    $settings = \App\Core\Utils::getSettings();
    $page_title = $settings['site_title'] . ' - Wartung';
    $maintenance_message = $settings['maintenance_message']; // Wird in 503.php verwendet
    
    // Lade die Wartungsseite
    // $config ist bereits global durch init.php
    include_once __DIR__ . '/pages/partials/header.php'; // Minimaler Header
    include_once __DIR__ . '/pages/errors/503.php';      // Die Wartungsseite
    include_once __DIR__ . '/pages/partials/footer.php'; // Minimaler Footer
    
    exit(); // Beende die Skriptausführung hier
}


// 3. Router einrichten und Routen laden (zuvor Schritt 2)
$router = new \App\Core\Router();
$routes = require __DIR__ . '/config/routes.php';
foreach ($routes as $pattern => $handler) {
    $router->add($pattern, $handler);
}

// 4. Aktuelle Anfrage-URL ermitteln (zuvor Schritt 3)
$request_uri = $_GET['url'] ?? '/';
$request_path = trim(parse_url($request_uri, PHP_URL_PATH), '/');

// 5. Passende Route finden (zuvor Schritt 4)
$routeInfo = $router->resolve($request_path);

// 6. Route verarbeiten oder 404-Fehler anzeigen (zuvor Schritt 5)
if ($routeInfo) {
    $handler = $routeInfo['handler'];
    $matches = $routeInfo['matches'];

    // Prüfen, ob der Handler eine Controller-Methode ist
    if (is_array($handler) && class_exists($handler[0]) && method_exists($handler[0], $handler[1])) {
        $controllerClass = $handler[0];
        $method = $handler[1];
        $controller = new $controllerClass();
        // Ruft die Controller-Methode auf und übergibt URL-Parameter (z.B. IDs)
        call_user_func_array([$controller, $method], $matches);
    }
    // Fallback für einfache, dateibasierte Routen
    elseif (is_string($handler) && file_exists(__DIR__ . '/' . $handler)) {
        include __DIR__ . '/' . $handler;
    }
    // Wenn der Handler ungültig ist
    else {
        http_response_code(500);
        echo "Fehler: Route-Handler für '{$request_path}' ist ungültig konfiguriert.";
    }
} else {
    // Keine passende Route gefunden -> 404
    http_response_code(404);
    $page_title = '404 - Seite nicht gefunden';
    // HINWEIS: Die Pfade zu den Partials müssen vom Stammverzeichnis aus korrekt sein.
    include_once __DIR__ . '/pages/partials/header.php';
    include_once __DIR__ . '/pages/errors/404.php';
    include_once __DIR__ . '/pages/partials/footer.php';
}