<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $app->get('/normalized-optional[/{value}]',
        function (Request $request, Response $response, array $args): Response {
            $response->getBody()->write($args['value'] ?? 'absent');
            return $response;
        });

    $app->get('/normalized-static[.json]',
        function (Request $request, Response $response): Response {
            $response->getBody()->write('static optional');
            return $response;
        });
};
