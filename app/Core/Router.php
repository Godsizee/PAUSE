<?php
namespace App\Core;
class Router
{
    private array $routes = [];
    public function add(string $pattern, $handler): void
    {
        $this->routes[$pattern] = $handler;
    }
    public function resolve(string $uri): ?array
    {
        foreach ($this->routes as $pattern => $handler) {
            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); 
                return [
                    'handler' => $handler,
                    'matches' => $matches
                ];
            }
        }
        return null;
    }
}