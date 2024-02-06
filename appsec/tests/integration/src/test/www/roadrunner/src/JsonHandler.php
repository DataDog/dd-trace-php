<?php

namespace App;

use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\Request;

class JsonHandler
{
    /**
     * @param Request $req
     * @return Response
     */
    public function handle(Request $req): Response
    {
        if (isset($req->query['block'])) {
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
