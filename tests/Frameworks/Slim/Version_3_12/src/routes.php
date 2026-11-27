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

    $app->get('/parameterized/{value}', function (Request $request, Response $response, array $args) {
        $value = $args['value'];
        $response->getBody()->write("Hello, $value");
        return $response;
    });

    $app->get('/dynamic-path/{param01}', function (Request $request, Response $response, array $args) {
        $response->getBody()->write("Dynamic path: " . $args['param01']);
        return $response;
    });

    // The second group is optional so a request can omit it: only the parameters present in the URL are captured.
    $app->get('/dynamic_route/{param01}[/static/{param02}]', function (Request $request, Response $response, array $args) {
        $response->getBody()->write("Dynamic route: " . implode(',', $args));
        return $response;
    });

    // Multi-verb route: the endpoint collector must emit one entry per method.
    $app->map(['GET', 'POST'], '/multi-method', function (Request $request, Response $response) {
        $response->getBody()->write('multi-method:' . $request->getMethod());
        return $response;
    });

    $app->get('/telemetry', function (Request $request, Response $response) {
        \dd_trace_internal_fn('finalize_telemetry');
        $response->getBody()->write('Done');
        return $response;
    });

    $app->get('/[{name}]', function (Request $request, Response $response, array $args) use ($container) {
        // Sample log message
        $container->get('logger')->info("Slim-Skeleton '/' route");

        // Render index view
        return $container->get('renderer')->render($response, 'index.phtml', $args);
    });
};
