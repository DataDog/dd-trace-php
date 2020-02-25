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

    $app->get('/http_response_code/success', function(Request $request, Response $response, $args) {
        http_response_code(200);
        return $response->withStatus(200);
    });

    $app->get('/http_response_code/error', function (Request $request, Response $response, $args) {
        http_response_code(500);
        return $response->withStatus(500);
    });

    $app->get('/[{name}]', function (Request $request, Response $response, array $args) use ($container) {
        // Sample log message
        $container->get('logger')->info("Slim-Skeleton '/' route");

        // Render index view
        return $container->get('renderer')->render($response, 'index.phtml', $args);
    });
};
