<?php

namespace App;

use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\Request;

class HomePageHandler {

    /**
     * @param Request $req
     * @return Response
     */
    public function handle($req): Response
    {
        if (isset($req->query['user']) && extension_loaded('ddappsec')) {
            \datadog\appsec\track_user_login_success_event($req->query['user'],
                [
                    'email' => 'jean.example@example.com',
                    'session_id' => '987654321',
                    'role' => 'admin'
                ]);
        }

        $status = 200;
        if (isset($req->query['status'])) {
            $status = (int) $req->query['status'];
        }
        return new Response(
            $status,
            ['Content-Type' => 'text/plain'],
            "Hello world!"
        );
    }
}
