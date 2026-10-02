<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PhpInfoHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \ob_start();
        phpinfo();
        $phpinfo = \ob_get_clean();

        return new Response(
            200,
            ['Content-Type' => 'text/html'],
            $phpinfo
        );
    }
}
