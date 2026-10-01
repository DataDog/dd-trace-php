<?php

namespace App;

class Router {
    private array $routes = [];

    public function addRoute($path, $handler): void
    {
        $this->routes[$path] = $handler;
    }

    public function getHandler($path) {
        return $this->routes[$path] ?? null;
    }
}
