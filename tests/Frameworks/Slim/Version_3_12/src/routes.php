<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

return function (App $app) {
    $container = $app->getContainer();

    $app->get('/simple', function (Request $request, Response $response, $args) {
        return "Simple :)\n";
    })->setName('simple-route');

    $app->get('/simple_view', '\\App\\SimpleViewController:index');

    $app->get('/error', function (Request $request, Response $response, $args) {
        throw new \Exception('Foo error');
    });

    $app->get('/[{name}]', function (Request $request, Response $response, array $args) use ($container) {
        // Sample log message
        $container->get('logger')->info("Slim-Skeleton '/' route");

        // Render index view
        return $container->get('renderer')->render($response, 'index.phtml', $args);
    });
};
