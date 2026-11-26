<?php
declare(strict_types=1);

use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        // CORS Pre-Flight OPTIONS Request Handler
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->group('/users', function (Group $group) {
        $group->get('', ListUsersAction::class);
        $group->get('/{id}', ViewUserAction::class);
    });

    $app->get('/simple', function (Request $request, Response $response, $args) {
        $response->getBody()->write("Simple :)\n");
        return $response;
    })->setName('simple-route');

    // Create Twig
    $twig = Twig::create(__DIR__ . '/../templates');

    // Add Twig-View Middleware
    $app->add(TwigMiddleware::create($app, $twig));

    $app->get('/simple_view', function (Request $request, Response $response) {
        $view = Twig::fromRequest($request);
        return $view->render($response, 'simple_view.phtml');
    });

    $app->get('/error', function (Request $request, Response $response, $args) {
        throw new \Exception('Foo error');
    });

    $app->get('/parameterized/{value}', function (Request $request, Response $response, $args) {
        $value = $args['value'];
        $response->getBody()->write("Hello, $value");
        return $response;
    });

    $app->get('/dynamic-path/{param01}', function (Request $request, Response $response, $args) {
        $response->getBody()->write("Dynamic path: " . $args['param01']);
        return $response;
    });

    // The second group is optional so a request can omit it: only the parameters present in the URL are captured.
    $app->get('/dynamic_route/{param01}[/static/{param02}]', function (Request $request, Response $response, $args) {
        $response->getBody()->write("Dynamic route: " . implode(',', $args));
        return $response;
    });
};
