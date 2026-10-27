<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JsonHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        $qp = $req->getQueryParams();
        if (isset($qp['block'])) {
            $poison = 'block_this';
        } else {
            $poison = 'poison';
        }
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => ['Hello world!', 42, true, $poison]])
        );
    }
}
