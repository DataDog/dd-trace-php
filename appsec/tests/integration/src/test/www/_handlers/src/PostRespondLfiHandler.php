<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PostRespondLfiHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        // Schedule fopen('../etc/passwd') to run after respond() returns.
        // worker.php consumes this callback after respond(), which is after
        // ddappsec's request_shutdown has been sent.
        $GLOBALS['_rr_post_respond'] = static function () {
            @fopen('../etc/passwd', 'r');
        };
        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
