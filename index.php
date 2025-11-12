<?php
require_once __DIR__ . '/init.php';
if (defined('MAINTENANCE_MODE_ACTIVE') && MAINTENANCE_MODE_ACTIVE === true) {
    http_response_code(503); 
    $settings = \App\Core\Utils::getSettings();
    $page_title = $settings['site_title'] . ' - Wartung';
    $maintenance_message = $settings['maintenance_message']; 
    include_once __DIR__ . '/pages/partials/header.php'; 
    include_once __DIR__ . '/pages/errors/503.php';      
    include_once __DIR__ . '/pages/partials/footer.php'; 
    exit(); 
}
$router = new \App\Core\Router();
$routes = require __DIR__ . '/config/routes.php';
foreach ($routes as $pattern => $handler) {
    $router->add($pattern, $handler);
}
$request_uri = $_GET['url'] ?? '/';
$request_path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
$routeInfo = $router->resolve($request_path);
if ($routeInfo) {
    $handler = $routeInfo['handler'];
    $matches = $routeInfo['matches'];
    if (is_array($handler) && class_exists($handler[0]) && method_exists($handler[0], $handler[1])) {
        $controllerClass = $handler[0];
        $method = $handler[1];
        $controller = new $controllerClass();
        call_user_func_array([$controller, $method], $matches);
    }
    elseif (is_string($handler) && file_exists(__DIR__ . '/' . $handler)) {
        include __DIR__ . '/' . $handler;
    }
    else {
        http_response_code(500);
        echo "Fehler: Route-Handler für '{$request_path}' ist ungültig konfiguriert.";
    }
} else {
    http_response_code(404);
    $page_title = '404 - Seite nicht gefunden';
    include_once __DIR__ . '/pages/partials/header.php';
    include_once __DIR__ . '/pages/errors/404.php';
    include_once __DIR__ . '/pages/partials/footer.php';
}