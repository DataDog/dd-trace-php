<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HomePageHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        $qp = $req->getQueryParams();
        if (isset($qp['user']) && extension_loaded('ddappsec')) {
            \datadog\appsec\track_user_login_success_event(
                $qp['user'],
                [
                    'email' => 'jean.example@example.com',
                    'session_id' => '987654321',
                    'role' => 'admin'
                ]
            );
        }

        $status = 200;
        if (isset($qp['status'])) {
            $status = (int) $qp['status'];
        }
        return new Response(
            $status,
            ['Content-Type' => 'text/plain'],
            "Hello world!"
        );
    }
}
