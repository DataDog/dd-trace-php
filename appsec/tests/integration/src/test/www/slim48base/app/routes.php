<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $app->setBasePath('/normalized-base');
    $app->get('/item/{id}',
        function (Request $request, Response $response, array $args): Response {
            $response->getBody()->write($args['id']);
            return $response;
        });
};
