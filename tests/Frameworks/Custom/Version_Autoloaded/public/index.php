<?php

require __DIR__ . '/../vendor/autoload.php';

$dispatcher = FastRoute\simpleDispatcher(
    include __DIR__ . '/../src/routes.php'
);

// Fetch method and URI from somewhere
$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Strip query string (?foo=bar) and decode URI
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        header('HTTP/1.0 404 Not Found');
        die('Route not found');
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        header('HTTP/1.0 405 Method Not Allowed');
        die('Method not allowed');
        break;
    case FastRoute\Dispatcher::FOUND:
        list(, $handler, $vars) = $routeInfo;
        $controller = new $handler($vars);
        $controller->render();
        break;
}
