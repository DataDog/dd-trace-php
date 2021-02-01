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
};
